<?php

namespace App\Services\Scholarship;

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
     *     total_to_pay: string,
     *     is_payable: bool,
     *     blocking_reasons: array<int, array{code: string, message: string}>,
     *     outcome: null,
     *     outcome_reason: null,
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
            ]);

        return $refrends->map(function ($row) {
            $hasEnrollment  = $row->enrollment !== null && $row->enrollment !== '';
            $hasPaymentData = $row->bank_name !== null;

            $evaluation = $this->evaluator->evaluate($row, $hasEnrollment, $hasPaymentData);

            return [
                'refrend_id'       => $row->refrend_id,
                'user_id'          => $row->user_id,
                'snapshot_name'    => $row->snapshot_name,
                'enrollment'       => $row->enrollment,
                'bank_name'        => $row->bank_name,
                'account_number'   => $row->account_number,
                'total_to_pay'     => $this->totalToPay($row),
                'is_payable'       => $evaluation['is_payable'],
                'blocking_reasons' => $evaluation['blocking_reasons'],
                'outcome'          => null,
                'outcome_reason'   => null,
            ];
        })->values()->all();
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
