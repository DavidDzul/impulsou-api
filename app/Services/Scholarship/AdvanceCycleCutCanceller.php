<?php

namespace App\Services\Scholarship;

use App\Exceptions\CycleCutBlockedException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * sdd/revertir-pago-adelantado, design A2 (S4b): cancels the advance payments
 * of a becario when a manual cycle cut (BAJA_DEFINITIVA / EGRESADO) happens.
 * Advances are CANCELLED, never deleted.
 *
 * Target months: every advance month of the user with status != CANCELLED and
 * period >= cut period. THE CUT MONTH IS INCLUDED (decision 3b); months before
 * the cut are untouched. Per header: all months CANCELLED -> CANCELLED, else
 * PARTIALLY_CANCELLED. Money per origin:
 *   - origin not paid: advance_payment_amount -= cancelled (floored at 0.00);
 *   - origin paid (batch, cheque or PAID): money untouched and the cancelled
 *     amount is recorded on the header as overpaid_amount.
 *
 * API: plan() is pure (no IO); loadCandidates($lock) reads (and, when asked,
 * locks refrends asc -> headers asc -> months asc, re-checking PAID on the
 * locked later refrends); cancelAfterCut() = lock + plan + apply; preview() =
 * no-lock read + plan, ZERO writes.
 *
 * Not in this class (S5, CloseScholarshipCycleAction): the origin audit log
 * ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT, the "Cancelado por ..." cascade
 * notes (fed by AdvanceCutPlan::$advanceRefrendIds) and the reconciler no-op.
 * Amounts are handled with bcmath strings, never floats.
 *
 * sdd/retenciones-pendientes (PR2): the anchor of the cancellation is either
 * the cut ScholarshipRefrend (manual cut, automatic egreso) or a CutAnchor
 * (user deactivation: the current month, no cut refrend). The actor is
 * nullable (system, automatic egreso). With no cut refrend, an anchor-month
 * advance month whose refrend is already locked/batched is consumed history
 * and is excluded; a later locked/batched month still blocks (anomalous state).
 */
class AdvanceCycleCutCanceller
{
    private const SCALE = 2;

    /**
     * @return array{months: Collection<int, ScholarshipAdvancePaymentMonth>, origins: Collection<int, ScholarshipRefrend>, headers: Collection<int, ScholarshipAdvancePayment>}
     *         months = candidate months; headers = headers with at least one
     *         candidate, each carrying ALL its months ('months') and its origin ('originRefrend').
     */
    public function loadCandidates(CutAnchor|ScholarshipRefrend $cut, bool $lock): array
    {
        $cut    = $this->anchorOf($cut);
        $cutAbs = PayableWithholdingWindow::absoluteMonth($cut->year, $cut->month);

        $discovered = ScholarshipAdvancePaymentMonth::where('user_id', $cut->userId)
            ->where('status', '!=', ScholarshipAdvancePaymentMonth::STATUS_CANCELLED)
            ->where(function (Builder $q) use ($cut) {
                $q->where('period_year', '>', $cut->year)
                    ->orWhere(function (Builder $same) use ($cut) {
                        $same->where('period_year', $cut->year)
                            ->where('period_month', '>=', $cut->month);
                    });
            })
            ->orderBy('id')
            ->get();

        if ($discovered->isEmpty()) {
            return ['months' => collect(), 'origins' => collect(), 'headers' => collect()];
        }

        $headerIds = $discovered->pluck('advance_payment_id')->unique()->sort()->values()->all();
        $originIds = ScholarshipAdvancePayment::whereIn('id', $headerIds)->pluck('origin_refrend_id')->all();

        $refrendIds = $discovered->pluck('refrend_id')->filter()->merge($originIds)->unique()->sort()->values()->all();

        // Lock order: refrends asc -> headers asc -> months asc.
        $refrends = $this->locked(ScholarshipRefrend::whereIn('id', $refrendIds)->orderBy('id'), $lock)->get()->keyBy('id');
        $headers  = $this->locked(ScholarshipAdvancePayment::whereIn('id', $headerIds)->orderBy('id'), $lock)->get()->keyBy('id');
        $allMonths = $this->locked(ScholarshipAdvancePaymentMonth::whereIn('advance_payment_id', $headerIds)->orderBy('id'), $lock)->get();

        $candidates = $allMonths
            ->filter(fn (ScholarshipAdvancePaymentMonth $m) => $this->isCandidate($m, $cutAbs)
                && ! $this->isConsumedAnchorMonth($m, $cut, $cutAbs, $refrends))
            ->values();

        $withCandidates = $candidates->pluck('advance_payment_id')->unique()->all();
        $headers        = $headers->filter(fn (ScholarshipAdvancePayment $h) => in_array($h->id, $withCandidates, true));

        foreach ($headers as $header) {
            $header->setRelation('months', $allMonths->where('advance_payment_id', $header->id)->values());
            $header->setRelation('originRefrend', $refrends->get($header->origin_refrend_id));
        }

        $origins = $headers->map(fn (ScholarshipAdvancePayment $h) => $h->originRefrend)->filter()->keyBy('id');

        if ($lock) {
            $this->assertLaterRefrendsNotPaid($cut, $candidates, $origins, $refrends);
        }

        return ['months' => $candidates, 'origins' => $origins, 'headers' => $headers];
    }

    /**
     * Pure: no IO. Relations ('months', 'originRefrend') must be preloaded.
     *
     * @param Collection<int, ScholarshipAdvancePaymentMonth> $months  candidate months
     * @param Collection<int, ScholarshipAdvancePayment>      $headers headers with candidates
     */
    public function plan(CutAnchor|ScholarshipRefrend $cut, Collection $months, Collection $headers): AdvanceCutPlan
    {
        if ($months->isEmpty()) {
            return AdvanceCutPlan::empty();
        }

        $cut    = $this->anchorOf($cut);
        $cutAbs = PayableWithholdingWindow::absoluteMonth($cut->year, $cut->month);

        $sortedMonths = $months->sortBy(fn (ScholarshipAdvancePaymentMonth $m) => [
            PayableWithholdingWindow::absoluteMonth($m->period_year, $m->period_month),
            $m->id,
        ])->values();

        $monthRows = $sortedMonths->map(fn (ScholarshipAdvancePaymentMonth $m) => [
            'id'           => (int) $m->id,
            'header_id'    => (int) $m->advance_payment_id,
            'refrend_id'   => $m->refrend_id !== null ? (int) $m->refrend_id : null,
            'period_year'  => (int) $m->period_year,
            'period_month' => (int) $m->period_month,
            'amount'       => $this->money($m->amount),
            'is_cut_month' => PayableWithholdingWindow::absoluteMonth($m->period_year, $m->period_month) === $cutAbs,
        ])->all();

        $totalCancelled = $totalOverpaid = $totalReduction = '0.00';
        $originRows     = [];

        foreach ($headers->sortBy('id') as $header) {
            $cancelledIds = $sortedMonths->where('advance_payment_id', $header->id)->pluck('id')->all();
            if ($cancelledIds === []) {
                continue;
            }

            $cancelled = '0.00';
            foreach ($sortedMonths->where('advance_payment_id', $header->id) as $m) {
                $cancelled = bcadd($cancelled, $this->money($m->amount), self::SCALE);
            }

            $remaining = $header->months->contains(
                fn (ScholarshipAdvancePaymentMonth $m) => $m->status !== ScholarshipAdvancePaymentMonth::STATUS_CANCELLED
                    && ! in_array($m->id, $cancelledIds, true)
            );

            $origin = $header->originRefrend;
            $paid   = AdvanceRevertEligibility::isOriginPaid($origin);

            if ($paid) {
                $overpaid  = $cancelled;
                $reduction = '0.00';
            } else {
                $current   = $this->money($origin->advance_payment_amount);
                $overpaid  = '0.00';
                $reduction = bccomp($current, $cancelled, self::SCALE) < 0 ? $current : $cancelled;
            }

            $originRows[] = [
                'header_id'                 => (int) $header->id,
                'origin_refrend_id'         => (int) $origin->id,
                'origin_period_year'        => (int) $origin->period_year,
                'origin_period_month'       => (int) $origin->period_month,
                'origin_paid'               => $paid,
                'cancelled_amount'          => $cancelled,
                'overpaid_amount'           => $overpaid,
                'advance_payment_reduction' => $reduction,
                'resulting_header_status'   => $remaining
                    ? ScholarshipAdvancePayment::STATUS_PARTIALLY_CANCELLED
                    : ScholarshipAdvancePayment::STATUS_CANCELLED,
            ];

            $totalCancelled = bcadd($totalCancelled, $cancelled, self::SCALE);
            $totalOverpaid  = bcadd($totalOverpaid, $overpaid, self::SCALE);
            $totalReduction = bcadd($totalReduction, $reduction, self::SCALE);
        }

        $advanceRefrendIds = $sortedMonths
            ->filter(fn (ScholarshipAdvancePaymentMonth $m) => $m->refrend_id !== null
                && PayableWithholdingWindow::absoluteMonth($m->period_year, $m->period_month) > $cutAbs)
            ->pluck('refrend_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return new AdvanceCutPlan($monthRows, $originRows, $totalCancelled, $totalOverpaid, $totalReduction, $advanceRefrendIds);
    }

    /** Locks, plans and applies. Must run inside the caller's transaction (nested is fine). */
    public function cancelAfterCut(CutAnchor|ScholarshipRefrend $cut, string $reason, ?int $userId): AdvanceCutPlan
    {
        $cut = $this->anchorOf($cut);

        return DB::transaction(function () use ($cut, $reason, $userId) {
            $loaded = $this->loadCandidates($cut, true);
            $plan   = $this->plan($cut, $loaded['months'], $loaded['headers']);

            if (! $plan->isEmpty()) {
                $this->apply($plan, $loaded, $cut, $reason, $userId);
            }

            return $plan;
        });
    }

    /** Read-only: same candidates and plan as cancelAfterCut, no locks, no writes. */
    public function preview(CutAnchor|ScholarshipRefrend $cut): AdvanceCutPlan
    {
        $cut    = $this->anchorOf($cut);
        $loaded = $this->loadCandidates($cut, false);

        return $this->plan($cut, $loaded['months'], $loaded['headers']);
    }

    /**
     * @param array{months: Collection, origins: Collection, headers: Collection} $loaded
     */
    private function apply(AdvanceCutPlan $plan, array $loaded, CutAnchor $cut, string $reason, ?int $userId): void
    {
        $now = now();

        ScholarshipAdvancePaymentMonth::whereIn('id', array_column($plan->months, 'id'))
            ->update(['status' => ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, 'updated_at' => $now]);

        foreach ($plan->origins as $row) {
            /** @var ScholarshipAdvancePayment $header */
            $header = $loaded['headers']->get($row['header_id']);

            $header->update([
                'status'                   => $row['resulting_header_status'],
                'cancelled_at'             => $now,
                'cancelled_by_id'          => $userId,
                'cancel_reason'            => $reason,
                'cancelled_cut_refrend_id' => $cut->cutRefrendId,
                'cancelled_amount'         => bcadd($this->money($header->cancelled_amount), $row['cancelled_amount'], self::SCALE),
                'overpaid_amount'          => bcadd($this->money($header->overpaid_amount), $row['overpaid_amount'], self::SCALE),
            ]);

            if (bccomp($row['advance_payment_reduction'], '0', self::SCALE) > 0) {
                $origin = $header->originRefrend;
                $origin->update([
                    'advance_payment_amount' => bcsub(
                        $this->money($origin->advance_payment_amount),
                        $row['advance_payment_reduction'],
                        self::SCALE
                    ),
                ]);
            }
        }
    }

    /**
     * Race guard (design: "re-check PAID on the locked later rows"): a later
     * refrend touched by the cancellation that got locked/batched since the
     * cycle-cut guards ran still blocks the cut. The cut refrend itself and
     * origins before the cut (paid origins are allowed) are not checked.
     *
     * @param Collection<int, ScholarshipAdvancePaymentMonth> $candidates
     * @param Collection<int, ScholarshipRefrend>             $origins
     * @param Collection<int, ScholarshipRefrend>             $refrends   locked rows keyed by id
     */
    private function assertLaterRefrendsNotPaid(CutAnchor $cut, Collection $candidates, Collection $origins, Collection $refrends): void
    {
        $cutAbs = PayableWithholdingWindow::absoluteMonth($cut->year, $cut->month);

        $touchedIds = $candidates->pluck('refrend_id')->filter()->merge($origins->keys())->unique();

        $blocking = [];
        foreach ($touchedIds as $id) {
            /** @var ScholarshipRefrend|null $later */
            $later = $refrends->get($id);

            if ($later === null) {
                continue;
            }

            $laterAbs = PayableWithholdingWindow::absoluteMonth($later->period_year, $later->period_month);
            if ($laterAbs > $cutAbs && ($later->isLocked() || $later->payment_batch_id !== null)) {
                $blocking[$laterAbs] = ['year' => (int) $later->period_year, 'month' => (int) $later->period_month, 'reason' => 'PAID'];
            }
        }

        if ($blocking === []) {
            return;
        }

        ksort($blocking);
        $months = array_values($blocking);
        $labels = array_map(fn ($m) => sprintf('%02d/%d', $m['month'], $m['year']), $months);

        throw new CycleCutBlockedException(
            sprintf(
                'Tiene %s pagado(s) por adelantado o con movimientos posteriores al corte; resuélvalo(s) antes de marcar egreso o baja definitiva.',
                implode(' y ', $labels)
            ),
            $months
        );
    }

    private function anchorOf(CutAnchor|ScholarshipRefrend $cut): CutAnchor
    {
        return $cut instanceof CutAnchor ? $cut : CutAnchor::fromRefrend($cut);
    }

    /**
     * Deactivation only (no cut refrend): the anchor month is the current
     * month and a refrend of it that is already locked/batched is consumed
     * history, not cancellable. A cut/egreso anchor IS its own refrend, which
     * is cancelled even when locked.
     *
     * @param Collection<int, ScholarshipRefrend> $refrends locked/read rows keyed by id
     */
    private function isConsumedAnchorMonth(ScholarshipAdvancePaymentMonth $month, CutAnchor $cut, int $cutAbs, Collection $refrends): bool
    {
        if ($cut->cutRefrendId !== null
            || PayableWithholdingWindow::absoluteMonth($month->period_year, $month->period_month) !== $cutAbs
        ) {
            return false;
        }

        $refrend = $month->refrend_id !== null ? $refrends->get($month->refrend_id) : null;

        return $refrend !== null && ($refrend->isLocked() || $refrend->payment_batch_id !== null);
    }

    private function isCandidate(ScholarshipAdvancePaymentMonth $month, int $cutAbs): bool
    {
        return $month->status !== ScholarshipAdvancePaymentMonth::STATUS_CANCELLED
            && PayableWithholdingWindow::absoluteMonth($month->period_year, $month->period_month) >= $cutAbs;
    }

    /** @param Builder $query */
    private function locked($query, bool $lock)
    {
        return $lock ? $query->lockForUpdate() : $query;
    }

    private function money($value): string
    {
        return bcadd((string) ($value ?? '0'), '0', self::SCALE);
    }
}
