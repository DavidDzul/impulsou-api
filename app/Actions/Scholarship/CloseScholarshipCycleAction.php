<?php

namespace App\Actions\Scholarship;

use App\Enums\CycleClosureReason;
use App\Enums\RefrendStatus;
use App\Exceptions\CycleCutActiveTelmexCoverageException;
use App\Exceptions\CycleCutBlockedException;
use App\Exceptions\CycleCutPendingWithholdingsException;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\TelmexCoverage;
use App\Services\Scholarship\PayableWithholdingWindow;
use App\Services\ScholarshipLoggingService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * sdd/egresado-baja-definitiva, design D4 (PR2a minimal scope, extended in
 * PR2b per amendments #1987/#1988 with the full guard chain). Owns every
 * EGRESADO/BAJA_DEFINITIVA cut side effect beyond the refrend's own money
 * fields (already set by RecordPaymentSituationAction's switch branches
 * before this is called): four blocking guards evaluated before any further
 * mutation here, cascade-closing later open refrends (design D7), the
 * profile stop marker (design D1/D2), the user-level effect
 * (GraduateBecarioAction for EGRESADO, active=false for BAJA_DEFINITIVA,
 * design D10), and the CYCLE_CLOSED audit log (design D13).
 *
 * GUARD ORDER (first failing guard wins — documented here because the tasks
 * artifact and the orchestrator's launch prompt each suggested a slightly
 * different ordering; this is the resolved, final order used by the code):
 *   1. CYCLE_ALREADY_CLOSED              — a second cut on an
 *      already-closed profile (ScholarshipCycleClosedException).
 *   2. Own abonos (design D9)            — the refrend BEING cut already has
 *      active ScholarshipWithholdingPayment rows applied to it from an
 *      earlier, now-replaced resolution. Plain \DomainException — this is
 *      not one of the four codes this change introduces. The controller
 *      also `prohibited_if`s `withholding_payments` for cut types, which
 *      blocks the SAME-REQUEST case; this guard catches the EARLIER-REQUEST
 *      case (a prior resolution already liquidated another retention through
 *      this refrend) that a request-shape validation rule cannot see.
 *   3. CYCLE_CUT_BLOCKED                 — a later period is advance-paid
 *      PENDING, already paid/locked/batched, or already has abonos applied
 *      to it (design D7 / amendment-2).
 *   4. CYCLE_CUT_ACTIVE_TELMEX_COVERAGE  — an ACTIVA Telmex coverage exists
 *      (amendment-3; EN_COBRO/LIQUIDADA/CANCELADA do not block).
 *   5. CYCLE_CUT_PENDING_WITHHOLDINGS    — ANY PENDING ScholarshipWithholding
 *      row exists for the becario, in or out of the payable window
 *      (amendment-4 — no acknowledge flag, no FORFEITED status; both moved
 *      to a future "Retenciones pendientes" change).
 * All five checks run inside the SAME DB transaction RecordPaymentSituation-
 * Action already opened, so a failure here rolls back the refrend's own
 * field updates too — no partial state is ever committed, even though in
 * source order this method runs after `$refrend->update($updates)` within
 * that transaction.
 *
 * Tolerates a missing ScholarshipProfile: several call sites (unit tests
 * exercising only the money math in RecordPaymentSituationAction, and in
 * principle any becario without a profile row) never create one — the
 * marker is simply not persisted in that case, but every guard and the
 * user-level effect still run. In production every becario reaching a
 * resolution flow has a profile (required for monthly_amount), so this is a
 * defensive no-op, not an expected path.
 */
class CloseScholarshipCycleAction
{
    public function __construct(
        private GraduateBecarioAction $graduate,
        private ScholarshipLoggingService $logging
    ) {
    }

    public function execute(ScholarshipRefrend $refrend, string $reason, int $userId): void
    {
        $profile = ScholarshipProfile::where('user_id', $refrend->user_id)->first();

        if ($profile !== null && $profile->cycle_closed_period !== null) {
            throw new ScholarshipCycleClosedException(
                'Este becario ya tiene un ciclo cerrado previamente.'
            );
        }

        $this->guardOwnAbonos($refrend);
        $laterOpenRefrends = $this->guardBlockingMonths($refrend);
        $this->guardActiveTelmexCoverage($refrend);
        $this->guardPendingWithholdings($refrend);

        $closedLaterLabels = $this->cascadeCloseLaterRefrends($refrend, $laterOpenRefrends, $reason, $userId);

        if ($profile !== null) {
            $profile->update([
                'cycle_closed_period'     => Carbon::create($refrend->period_year, $refrend->period_month, 1),
                'cycle_closure_reason'    => $reason,
                'cycle_closed_refrend_id' => $refrend->id,
            ]);
        }

        $this->logging->log(
            $refrend,
            'CYCLE_CLOSED',
            null,
            null,
            $closedLaterLabels === []
                ? sprintf('Ciclo cerrado (%s).', $reason)
                : sprintf(
                    'Ciclo cerrado (%s). Refrendos posteriores cerrados en cascada: %s.',
                    $reason,
                    implode(', ', $closedLaterLabels)
                )
        );

        if ($reason === CycleClosureReason::EGRESADO->value) {
            $this->graduate->execute(
                $refrend->user,
                $refrend,
                'Egreso registrado vía cierre de ciclo (EGRESADO).',
                $userId
            );

            return;
        }

        $refrend->user()->update(['active' => false]);
    }

    /** Guard #2 (design D9): the cut refrend itself cannot already be liquidating another retention. */
    private function guardOwnAbonos(ScholarshipRefrend $refrend): void
    {
        $hasOwnAbonos = $refrend->withholdingPayments()->where('is_voided', false)->exists();

        if ($hasOwnAbonos) {
            throw new \DomainException(
                'Este refrendo ya tiene abonos aplicados a otra retención; revierta esos abonos antes de cerrar el ciclo.'
            );
        }
    }

    /**
     * Guard #3 (design D7 / amendment-2): one later period blocks the cut
     * when it is advance-paid PENDING, already locked/batched, or already has
     * abonos applied to it. Returns the (non-blocking) later refrends still
     * open, so the caller can cascade-close them without a second query.
     *
     * @return Collection<int, ScholarshipRefrend>
     */
    private function guardBlockingMonths(ScholarshipRefrend $refrend): Collection
    {
        $cutAbs = PayableWithholdingWindow::absoluteMonth($refrend->period_year, $refrend->period_month);

        $laterRefrends = ScholarshipRefrend::where('user_id', $refrend->user_id)
            ->where('id', '!=', $refrend->id)
            ->get()
            ->filter(fn (ScholarshipRefrend $r) => PayableWithholdingWindow::absoluteMonth($r->period_year, $r->period_month) > $cutAbs);

        $blocking = [];

        foreach ($laterRefrends as $later) {
            $key = $later->period_year . '-' . $later->period_month;

            if ($later->isLocked() || $later->payment_batch_id !== null) {
                $blocking[$key] = ['year' => $later->period_year, 'month' => $later->period_month, 'reason' => 'PAID'];
            } elseif ($later->withholdingPayments()->where('is_voided', false)->exists()) {
                $blocking[$key] = ['year' => $later->period_year, 'month' => $later->period_month, 'reason' => 'ABONOS_APPLIED'];
            }
        }

        $pendingAdvanceMonths = ScholarshipAdvancePaymentMonth::where('user_id', $refrend->user_id)
            ->pending()
            ->get()
            ->filter(fn (ScholarshipAdvancePaymentMonth $m) => PayableWithholdingWindow::absoluteMonth($m->period_year, $m->period_month) > $cutAbs);

        foreach ($pendingAdvanceMonths as $month) {
            $key = $month->period_year . '-' . $month->period_month;
            // ADVANCE_PAID takes priority over a later-written PAID/ABONOS_APPLIED
            // entry for the same period — an advance-paid month is the most
            // actionable reason for staff to see first.
            $blocking[$key] = ['year' => $month->period_year, 'month' => $month->period_month, 'reason' => 'ADVANCE_PAID'];
        }

        if ($blocking !== []) {
            $months = array_values($blocking);
            usort($months, fn ($a, $b) => PayableWithholdingWindow::absoluteMonth($a['year'], $a['month']) <=> PayableWithholdingWindow::absoluteMonth($b['year'], $b['month']));

            $labels = array_map(fn ($m) => sprintf('%02d/%d', $m['month'], $m['year']), $months);

            throw new CycleCutBlockedException(
                sprintf(
                    'Tiene %s pagado(s) por adelantado o con movimientos posteriores al corte; resuélvalo(s) antes de marcar egreso o baja definitiva.',
                    implode(' y ', $labels)
                ),
                $months
            );
        }

        return $laterRefrends;
    }

    /** Guard #4 (amendment-3): an ACTIVA Telmex coverage blocks the cut outright. */
    private function guardActiveTelmexCoverage(ScholarshipRefrend $refrend): void
    {
        $coverage = TelmexCoverage::where('user_id', $refrend->user_id)
            ->where('status', 'ACTIVA')
            ->first();

        if ($coverage !== null) {
            throw new CycleCutActiveTelmexCoverageException(
                'Este becario tiene una cobertura Telmex ACTIVA; cancélela en Becas Telmex antes de cerrar el ciclo.',
                $coverage->id,
                $coverage->status,
                $coverage->start_period->toDateString()
            );
        }
    }

    /** Guard #5 (amendment-4): ANY pending withholding blocks the cut outright, no acknowledge flag. */
    private function guardPendingWithholdings(ScholarshipRefrend $refrend): void
    {
        $pending = ScholarshipWithholding::where('user_id', $refrend->user_id)->pending()->get();

        if ($pending->isEmpty()) {
            return;
        }

        $rows = $pending->map(fn (ScholarshipWithholding $w) => [
            'id'               => $w->id,
            'period_year'      => $w->period_year,
            'period_month'     => $w->period_month,
            'withheld_amount'  => $w->withheld_amount,
            'paid_amount'      => $w->paid_amount,
            'remaining_amount' => $w->remaining_amount,
        ])->values()->all();

        throw new CycleCutPendingWithholdingsException(
            'Tiene retenciones pendientes de pago; resuélvalas en Pago de meses retenidos antes de cerrar el ciclo.',
            $rows
        );
    }

    /**
     * Design D7: later open refrends (already confirmed non-blocking by
     * guardBlockingMonths) are closed the same way the cut refrend itself
     * is — $0, CANCELLED/CLOSED, locked. Their own origin withholding row
     * (if any) is cancelled too, guarded to paid_amount being 0 — by the
     * time this runs, guardPendingWithholdings has already rejected the cut
     * if ANY pending withholding existed anywhere for this user (including
     * one originating from a later refrend), so a PENDING row reaching this
     * point should be unreachable in practice; the paid_amount guard is a
     * second line of defense that never silently discards a
     * partially-settled retention.
     *
     * @param Collection<int, ScholarshipRefrend> $laterOpenRefrends
     * @return array<int, string> MM/YYYY labels of every refrend closed in cascade.
     */
    private function cascadeCloseLaterRefrends(ScholarshipRefrend $refrend, Collection $laterOpenRefrends, string $reason, int $userId): array
    {
        $closedLabels = [];

        foreach ($laterOpenRefrends as $later) {
            $gross       = (float) ($later->snapshot_gross_amount ?? $later->base_amount);
            $academicPct = (float) ($later->snapshot_discount_percentage ?? 0);
            $due         = round($gross * (1 - $academicPct / 100), 2);

            $later->update([
                'resolution_type'     => $reason,
                'resolution_notes'    => sprintf('Cerrado por %s en %02d/%d.', $reason, $refrend->period_month, $refrend->period_year),
                'final_amount'        => '0.00',
                'discount_percentage' => '100.00',
                'discount_amount'     => number_format($due, 2, '.', ''),
                'status'              => RefrendStatus::CANCELLED->value,
                'workflow_status'     => 'CLOSED',
                'locked_at'           => now(),
                'locked_by_id'        => $userId,
            ]);

            $originLedger = ScholarshipWithholding::where('origin_refrend_id', $later->id)
                ->where('status', '!=', 'CANCELLED')
                ->first();
            if ($originLedger !== null && (float) $originLedger->paid_amount <= 0.0) {
                $originLedger->update(['status' => 'CANCELLED']);
            }

            $label           = sprintf('%02d/%d', $later->period_month, $later->period_year);
            $closedLabels[]  = $label;

            $this->logging->log(
                $later,
                'CYCLE_CLOSED_CASCADE',
                null,
                null,
                sprintf('Cerrado en cascada por el corte de %02d/%d (%s).', $refrend->period_month, $refrend->period_year, $reason)
            );
        }

        return $closedLabels;
    }
}
