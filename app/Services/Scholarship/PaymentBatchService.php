<?php

namespace App\Services\Scholarship;

use App\Models\ScholarshipPaymentBatch;
use Illuminate\Support\Facades\DB;

/**
 * Emits the one PaymentBatchRow shape that powers both the pre-payment
 * review table (this PR) and the post-payment outcome report (a later PR
 * populates `outcome`/`outcome_reason` after BulkPayAction runs).
 *
 * Batch key is generation_id + campus + period_year + period_month, all
 * required (design D1 — a single generación+sede can have two different
 * periods simultaneously LISTO_PARA_PAGO, so the period must be part of the
 * key or "Pagar todos" could silently pay two payrolls at once).
 *
 * Deviation from the design sketch: `rows()` takes the four batch-key scalars
 * directly rather than a `BatchKey` value object — no such PHP class is
 * listed in the design's File Changes table (only a TS interface of the same
 * name for the frontend), and this mirrors the existing
 * RefrendBulkQueryService::buildTable(year, month, campus, generationId, ...)
 * convention it must stay consistent with.
 */
class PaymentBatchService
{
    public function __construct(private PaymentReadinessEvaluator $evaluator)
    {
    }

    /**
     * @return array<int, array{
     *     refrend_id: int,
     *     user_id: int,
     *     snapshot_name: string,
     *     enrollment: ?string,
     *     bank_name: ?string,
     *     account_number: ?string,
     *     rfc: ?string,
     *     payment_batch_id: ?int,
     *     total_to_pay: string,
     *     is_payable: bool,
     *     blocking_reasons: array<int, array{code: string, message: string}>,
     *     outcome: null,
     *     outcome_reason: null,
     *     has_incident: bool,
     *     has_pending_from_previous: bool,
     * }>
     */
    public function rows(int $generationId, string $campus, int $periodYear, int $periodMonth): array
    {
        $refrends = DB::table('scholarship_refrends as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('scholarship_payment_data as spd', 'spd.user_id', '=', 'r.user_id')
            ->where('r.snapshot_generation_id', $generationId)
            ->where('r.snapshot_campus', $campus)
            ->where('r.period_year', $periodYear)
            ->where('r.period_month', $periodMonth)
            ->orderBy('r.snapshot_name')
            ->get([
                'r.id as refrend_id',
                'r.user_id',
                'r.snapshot_name',
                'r.workflow_status',
                'r.status',
                'r.locked_at',
                'r.payment_batch_id',
                'r.final_amount',
                'r.amount_pending_from_previous',
                'r.refund_amount_from_previous',
                'u.enrollment',
                // scholarship_payment_data columns are NOT NULL (migration
                // 2026_09_11_000000_...:14-16), so a NULL here (from the
                // LEFT JOIN) unambiguously means "no row exists" — there is
                // no blank-field case to additionally check (see design).
                'spd.bank_name',
                'spd.account_number',
                // rfc is nullable (migration 2026_09_11_000000_...:17) —
                // added for PR3 so the readiness evaluator can enforce
                // BankDataValidator's RFC rule (design D2).
                'spd.rfc',
            ]);

        // Quick-glance indicator for has_incident: the same incidents()
        // relation ScholarshipPaymentController::document() already uses,
        // batched into one query keyed by refrend id (avoids N+1 across the
        // whole batch). Purely informational — incidencias never block
        // payment (PaymentReadinessEvaluator's decision, untouched here).
        $refrendIdsWithIncidents = DB::table('scholarship_refrend_incidents')
            ->whereIn('scholarship_refrend_id', $refrends->pluck('refrend_id'))
            ->distinct()
            ->pluck('scholarship_refrend_id')
            ->flip();

        return $refrends->map(function ($row) use ($refrendIdsWithIncidents) {
            $hasEnrollment  = $row->enrollment !== null && $row->enrollment !== '';
            $hasPaymentData = $row->bank_name !== null;

            $evaluation = $this->evaluator->evaluate(
                $row,
                $hasEnrollment,
                $hasPaymentData,
                $row->account_number,
                $row->rfc
            );

            return [
                'refrend_id'                => $row->refrend_id,
                'user_id'                   => $row->user_id,
                'snapshot_name'             => $row->snapshot_name,
                'enrollment'                => $row->enrollment,
                'bank_name'                 => $row->bank_name,
                'account_number'            => $row->account_number,
                'rfc'                       => $row->rfc,
                'payment_batch_id'          => $row->payment_batch_id,
                'total_to_pay'              => $this->totalToPay($row),
                'is_payable'                => $evaluation['is_payable'],
                'blocking_reasons'          => $evaluation['blocking_reasons'],
                'outcome'                   => null,
                'outcome_reason'            => null,
                'has_incident'              => $refrendIdsWithIncidents->has($row->refrend_id),
                'has_pending_from_previous' => (float) ($row->amount_pending_from_previous ?? 0) > 0,
            ];
        })->values()->all();
    }

    /**
     * Row source for the export path (bank-file summary + file itself,
     * sdd/becario-payment-bank-file-export/design D1). Sourced EXCLUSIVELY
     * from `$batch->refrends()` — the real FK relation — never from
     * `$batch->refrend_count`/`$batch->total_amount`, which are captured
     * pre-lock in ScholarshipPaymentController::process() and can overstate
     * the actually-paid set if BulkPayAction skipped a row under the lock.
     *
     * Reuses `totalToPay()` (stays private) via `$this->totalToPay($row)` —
     * no second implementation of the money formula (design D1).
     *
     * @return array<int, array{
     *     refrend_id: int,
     *     user_id: int,
     *     snapshot_name: string,
     *     rfc: ?string,
     *     account_number: ?string,
     *     total_to_pay: string,
     * }>
     */
    public function paidRows(ScholarshipPaymentBatch $batch): array
    {
        $rows = $batch->refrends()
            ->leftJoin('users as u', 'u.id', '=', 'scholarship_refrends.user_id')
            ->leftJoin('scholarship_payment_data as spd', 'spd.user_id', '=', 'scholarship_refrends.user_id')
            ->orderBy('scholarship_refrends.snapshot_name')
            ->get([
                'scholarship_refrends.id as refrend_id',
                'scholarship_refrends.user_id',
                'scholarship_refrends.snapshot_name',
                'scholarship_refrends.final_amount',
                'scholarship_refrends.amount_pending_from_previous',
                'scholarship_refrends.refund_amount_from_previous',
                'spd.account_number',
                'spd.rfc',
            ]);

        return $rows->map(fn ($row) => [
            'refrend_id'     => $row->refrend_id,
            'user_id'        => $row->user_id,
            'snapshot_name'  => $row->snapshot_name,
            'rfc'            => $row->rfc,
            'account_number' => $row->account_number,
            'total_to_pay'   => $this->totalToPay($row),
        ])->values()->all();
    }

    /**
     * Totals for the summary header (Requirement: "Batch readiness list with
     * per-row status and summary"). `total_amount` sums `total_to_pay` across
     * READY rows only — a blocked row's amount is not yet a committed payout.
     *
     * @param array<int, array{is_payable: bool, total_to_pay: string}> $rows
     * @return array{total: int, ready: int, blocking: int, total_amount: string}
     */
    public function summary(array $rows): array
    {
        $readyRows = array_values(array_filter($rows, fn (array $row) => $row['is_payable']));

        $totalAmount = array_reduce(
            $readyRows,
            fn (float $carry, array $row) => $carry + (float) $row['total_to_pay'],
            0.0
        );

        return [
            'total'        => count($rows),
            'ready'        => count($readyRows),
            'blocking'     => count($rows) - count($readyRows),
            'total_amount' => number_format($totalAmount, 2, '.', ''),
        ];
    }

    /**
     * Reuses RefrendBulkQueryService's exact existing formula
     * (final_amount + amount_pending_from_previous + refund_amount_from_previous),
     * formatted the same way ScholarshipRefrend::getTotalToPayAttribute()
     * does — as a decimal string, since the driver returns this value as a
     * string on MySQL and a float on SQLite (RefrendBulkQueryService.php:162-173
     * documents the same trap).
     */
    private function totalToPay(object $row): string
    {
        return number_format(
            (float) $row->final_amount
            + (float) ($row->amount_pending_from_previous ?? 0)
            + (float) ($row->refund_amount_from_previous ?? 0),
            2,
            '.',
            ''
        );
    }
}
