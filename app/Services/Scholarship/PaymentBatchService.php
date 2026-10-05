<?php

namespace App\Services\Scholarship;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipRefrend;
use App\Support\Scholarship\PaymentAmountFloor;
use App\Support\Scholarship\TelmexPaymentPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Emits the one PaymentBatchRow shape that powers both the pre-payment
 * review table (this PR) and the post-payment outcome report (a later PR
 * populates `outcome`/`outcome_reason` after BulkPayAction runs).
 *
 * Batch key is campus + period_year + period_month only (sdd/pagos-batch-sede-totals:
 * generation_id dropped from the key — a batch's rows may span multiple
 * generaciones at the same campus/period; design D1's original period
 * requirement still holds — a single sede can have two different periods
 * simultaneously LISTO_PARA_PAGO, so the period must be part of the key or
 * "Pagar todos" could silently pay two payrolls at once).
 *
 * Deviation from the design sketch: `rows()` takes the batch-key scalars
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
     *     only_pending_from_previous: bool,
     *     resolution_type: ?string,
     *     resolution_cause: ?string,
     *     advance_paid: bool,
     *     advance_paid_amount: ?string,
     *     advance_paid_origin_year: ?int,
     *     advance_paid_origin_month: ?int,
     *     advance_paid_divergence_reason: ?string,
     *     advance_payment_amount: string,
     *     snapshot_temporary_increase_amount: ?string,
     *     snapshot_temporary_increase_reason: ?string,
     *     snapshot_scholarship_type: string,
     *     base_amount: string,
     *     snapshot_monto_apoyo: ?string,
     * }>
     *
     * resolution_type/resolution_cause (sdd/resolution-status-visibility) are
     * purely informational: they are never read by PaymentReadinessEvaluator
     * and never participate in is_payable/blocking_reasons.
     *
     * ONE narrow, deliberate exception (sdd/egresado-status-timing, design
     * D5): resolution_type = 'EGRESO_RETICULA' — the server-only value
     * written by GenerateMonthlyRefrendsService for a becario's retícula
     * month+2 — is excluded in SQL by Filter C in rows() below. This does
     * NOT make resolution_type a payability input: Filter C REMOVES a row
     * from candidacy entirely (identical semantics and placement to Filter
     * A / TelmexPaymentPolicy::applyBatchCandidacy), it never grades one,
     * and PaymentReadinessEvaluator still reads no resolution_type at all.
     * Any future value stays purely informational unless it is added here
     * as an explicit candidacy exclusion with this same reasoning.
     *
     * advance_paid/advance_paid_amount/advance_paid_origin_year/
     * advance_paid_origin_month (sdd/pago-adelantado, design D6) follow the
     * exact same isolation — purely informational, never read by
     * PaymentReadinessEvaluator. Deliberately NOT emitted by paidRows()
     * (design's explicit scope boundary, mirroring resolution_type/
     * resolution_cause's own exclusion from that method).
     *
     * advance_payment_amount (sdd/pago-adelantado PR8) is DIFFERENT from
     * advance_paid_amount above — do not confuse the two: advance_paid_amount
     * means "this row IS one of the future months settled by someone else's
     * batch" (this refrend is a CHILD), while advance_payment_amount means
     * "this row itself has an advance payment registered against it, i.e.
     * it is the ORIGIN refrend of a batch" (design D6's
     * scholarship_refrends.advance_payment_amount column, already feeding
     * totalToPay() below). Both can theoretically be true on different rows
     * in the same batch. Always a decimal string, "0.00" when the column is
     * at its NOT NULL DEFAULT 0 — same convention as every other money field
     * in this row shape.
     *
     * snapshot_scholarship_type/base_amount/snapshot_monto_apoyo
     * (sdd/pagos-batch-sede-totals, design D9) are raw pass-throughs added
     * for the batch summary's type-partitioned money totals (see
     * summary() below). Purely informational, same invariant as every
     * other flag here: MUST NOT be read by PaymentReadinessEvaluator or
     * influence is_payable/blocking_reasons. Deliberately NOT added to
     * paidRows() — same scope boundary as resolution_type/advance_paid/
     * snapshot_temporary_increase_amount.
     */
    public function rows(string $campus, int $periodYear, int $periodMonth): array
    {
        $query = DB::table('scholarship_refrends as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('scholarship_payment_data as spd', 'spd.user_id', '=', 'r.user_id')
            ->where('r.snapshot_campus', $campus)
            ->where('r.period_year', $periodYear)
            ->where('r.period_month', $periodMonth);

        // Filter A (sdd/scholarship-telmex-iu-split, design D1/D3): a pure
        // TELMEX row without a currently active temporary increase has
        // nothing payable and is not a batch candidate at all.
        TelmexPaymentPolicy::applyBatchCandidacy($query, 'r');

        // Filter C (sdd/egresado-status-timing, design D5): the
        // auto-generated retícula month+2 egreso row is not a batch
        // candidate at all. Grouped whereNull OR != is MANDATORY — a bare
        // `!=` would evaluate NULL for every unresolved row and silently
        // drop the entire normal payroll (SQL NULL semantics). Filter A
        // gets away with a bare `!=` only because snapshot_scholarship_type
        // is NOT NULL.
        $query->where(function ($where) {
            $where->whereNull('r.resolution_type')
                ->orWhere('r.resolution_type', '!=', ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA);
        });

        $refrends = $query
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
                // advance_payment_amount (sdd/pago-adelantado, design D6):
                // NOT NULL DEFAULT 0, feeds totalToPay() below — see the
                // lockstep cross-reference comment there. Selected here (not
                // just in the indicator lookup) because totalToPay() reads
                // it directly off this row object.
                'r.advance_payment_amount',
                // resolution_type/resolution_cause (sdd/resolution-status-visibility):
                // informational-only, added for the row-level indicator chip.
                // DB::table() bypasses Eloquent casts, so these arrive as raw
                // string|null — never a BackedEnum, no normalization needed.
                'r.resolution_type',
                'r.resolution_cause',
                // snapshot_scholarship_type/snapshot_temporary_increase_amount
                // (sdd/scholarship-telmex-iu-split, design D3/D9): required
                // both by PaymentReadinessEvaluator's TELMEX_NOT_PAYABLE guard
                // and by the excluded_from_bank_file chip below. Raw string
                // here (DB::table() bypasses the Eloquent enum cast) —
                // TelmexPaymentPolicy::type() normalizes both shapes.
                'r.snapshot_scholarship_type',
                'r.snapshot_temporary_increase_amount',
                // snapshot_temporary_increase_reason (sdd/temporary-increase-visibility,
                // design D7): the amount above was already selected (consumed
                // internally by TelmexPaymentPolicy); the reason was not —
                // both are now also surfaced on the returned row (see below)
                // for the row-level chip. Purely informational, like every
                // other chip field here.
                'r.snapshot_temporary_increase_reason',
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
                // base_amount/snapshot_monto_apoyo (sdd/pagos-batch-sede-totals,
                // design D9): raw pass-throughs feeding summary()'s type-
                // partitioned money totals. r.snapshot_scholarship_type is
                // already selected above.
                'r.base_amount',
                'r.snapshot_monto_apoyo',
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

        // Advance-paid annotation (sdd/pago-adelantado, spec "Row indicator
        // in administration-panel Pagos table") — same shape/lookup as
        // RefrendBulkQueryService::buildTable()'s twin block. Deliberately
        // NOT built/used inside paidRows() (design's explicit scope
        // boundary — mirrors resolution_type/resolution_cause).
        $advancePaidByRefrendId = DB::table('scholarship_advance_payment_months as apm')
            ->join('scholarship_advance_payments as ap', 'ap.id', '=', 'apm.advance_payment_id')
            ->whereIn('apm.refrend_id', $refrends->pluck('refrend_id'))
            ->select([
                'apm.refrend_id', 'apm.amount', 'apm.divergence_reason',
                'ap.origin_period_year', 'ap.origin_period_month',
            ])
            ->get()
            ->keyBy('refrend_id');

        return $refrends->map(function ($row) use ($refrendIdsWithIncidents, $advancePaidByRefrendId) {
            $advance = $advancePaidByRefrendId->get($row->refrend_id);
            $hasEnrollment  = $row->enrollment !== null && $row->enrollment !== '';
            $hasPaymentData = $row->bank_name !== null;

            $evaluation = $this->evaluator->evaluate(
                $row,
                $hasEnrollment,
                $hasPaymentData,
                $row->account_number,
                $row->rfc
            );

            // Shared payable-floor rule (sdd/bank-file-minimum-deposit,
            // design D2): IU/TELMEX_IU rows at exactly total_to_pay == 0.00
            // floor to 0.01. Order-insensitive by construction — never
            // inverts the excluded_from_bank_file chip below (design R1).
            $totalToPay = PaymentAmountFloor::apply($row->snapshot_scholarship_type, $this->totalToPay($row));

            return [
                'refrend_id'                => $row->refrend_id,
                'user_id'                   => $row->user_id,
                'snapshot_name'             => $row->snapshot_name,
                'enrollment'                => $row->enrollment,
                'bank_name'                 => $row->bank_name,
                'account_number'            => $row->account_number,
                'rfc'                       => $row->rfc,
                'payment_batch_id'          => $row->payment_batch_id,
                'total_to_pay'              => $totalToPay,
                'is_payable'                => $evaluation['is_payable'],
                'blocking_reasons'          => $evaluation['blocking_reasons'],
                'outcome'                   => null,
                'outcome_reason'            => null,
                'has_incident'              => $refrendIdsWithIncidents->has($row->refrend_id),
                'has_pending_from_previous' => (float) ($row->amount_pending_from_previous ?? 0) > 0,
                // Distinguishes "also paying the current month" from "ONLY
                // settling a retained month, current month pays nothing" —
                // has_pending_from_previous alone can't tell those apart
                // (user-reported ambiguity in the "Incluye mes retenido" chip).
                'only_pending_from_previous' => (float) ($row->amount_pending_from_previous ?? 0) > 0
                    && (float) $row->final_amount <= 0,
                'resolution_type'           => $row->resolution_type,
                'resolution_cause'          => $row->resolution_cause,
                'advance_paid'              => $advance !== null,
                'advance_paid_amount'       => $advance !== null
                    ? number_format((float) $advance->amount, 2, '.', '')
                    : null,
                'advance_paid_origin_year'  => $advance->origin_period_year ?? null,
                'advance_paid_origin_month' => $advance->origin_period_month ?? null,
                // Row-level chip data for the divergence-reason indicator
                // (administration-panel, added 2026-09-27): set only when
                // staff overrode the safe $0 outcome with a reason on
                // arrival (RecordPaymentSituationAction / ApproveFullPaymentAction's
                // shared AdvancePaymentReconciler) — null otherwise, same
                // null-on-nothing convention as advance_paid_amount.
                'advance_paid_divergence_reason' => $advance->divergence_reason ?? null,
                // advance_payment_amount (sdd/pago-adelantado PR8): the
                // origin-refrend indicator — see the docblock above for why
                // this is NOT the same concept as advance_paid_amount.
                'advance_payment_amount'    => number_format((float) ($row->advance_payment_amount ?? 0), 2, '.', ''),
                // excluded_from_bank_file (sdd/scholarship-telmex-iu-split,
                // design D9): server-computed prediction of Filter B
                // (paidRows()'s export-time exclusion), using the exact same
                // policy and the exact same total_to_pay this row already
                // shows — never re-derived client-side. Purely informational,
                // like every other chip field: MUST NOT be read by
                // PaymentReadinessEvaluator or influence is_payable.
                'excluded_from_bank_file'   => TelmexPaymentPolicy::isExcludedFromBankFile(
                    $row->snapshot_scholarship_type,
                    $totalToPay
                ),
                // snapshot_temporary_increase_amount/_reason
                // (sdd/temporary-increase-visibility, design D7): row-level
                // chip data, raw pass-through of two frozen snapshot columns
                // — DB::table() bypasses the decimal:2 cast, so the amount
                // arrives as a raw string|null exactly as stored. Purely
                // informational, same invariant as every other flag here:
                // MUST NOT be read by PaymentReadinessEvaluator or influence
                // is_payable/blocking_reasons, and MUST NOT be derived from
                // or influence has_incident/scholarship_refrend_incidents —
                // the two are unrelated data sources. Deliberately NOT added
                // to paidRows() (same scope boundary as resolution_type/
                // advance_paid — see the boundary-lock test).
                'snapshot_temporary_increase_amount' => $row->snapshot_temporary_increase_amount,
                'snapshot_temporary_increase_reason' => $row->snapshot_temporary_increase_reason,
                // snapshot_scholarship_type/base_amount/snapshot_monto_apoyo
                // (sdd/pagos-batch-sede-totals, design D9): raw pass-through,
                // DB::table() bypasses the decimal:2/enum casts, so these
                // arrive exactly as stored. Purely informational, like every
                // other chip field above: MUST NOT be read by
                // PaymentReadinessEvaluator or influence is_payable/
                // blocking_reasons. Deliberately NOT added to paidRows()
                // (same scope boundary as the other informational fields —
                // see the boundary-lock test).
                'snapshot_scholarship_type' => $row->snapshot_scholarship_type,
                'base_amount'               => $row->base_amount,
                'snapshot_monto_apoyo'      => $row->snapshot_monto_apoyo,
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
                // advance_payment_amount (sdd/pago-adelantado, design D6):
                // selected so totalToPay() computes the correct exported
                // total — the money term is shared with rows(), unlike the
                // advance_paid indicator fields, which paidRows() never
                // exposes (design's explicit scope boundary).
                'scholarship_refrends.advance_payment_amount',
                // snapshot_scholarship_type (sdd/scholarship-telmex-iu-split,
                // design D4): needed by Filter B below. This is an Eloquent
                // relation query (not DB::table()), so this column arrives
                // enum-cast as a ScholarshipType object — the OPPOSITE shape
                // from rows()'s raw string (design D5) — never assume both
                // call sites see the same PHP type.
                'scholarship_refrends.snapshot_scholarship_type',
                'spd.account_number',
                'spd.rfc',
            ]);

        // Filter B (design D4): excluded BEFORE map(), so the row never
        // reaches ScholarshipPaymentController::paidAndBankValidatedRows()'s
        // BankDataValidator gate — that ordering is the entire point (a
        // TELMEX row with total_to_pay<=0 must silently vanish from the
        // export instead of 422-ing the whole batch).
        return $rows
            ->reject(fn ($row) => TelmexPaymentPolicy::isExcludedFromBankFile(
                $row->snapshot_scholarship_type,
                $this->totalToPay($row)
            ))
            ->map(fn ($row) => [
                'refrend_id'     => $row->refrend_id,
                'user_id'        => $row->user_id,
                'snapshot_name'  => $row->snapshot_name,
                'rfc'            => $row->rfc,
                'account_number' => $row->account_number,
                // Shared payable-floor rule (sdd/bank-file-minimum-deposit,
                // design D2), applied AFTER Filter B's reject() above, which
                // still evaluates the raw (unfloored) total.
                'total_to_pay'   => PaymentAmountFloor::apply($row->snapshot_scholarship_type, $this->totalToPay($row)),
            ])->values()->all();
    }

    /**
     * Totals for the summary header (Requirement: "Batch readiness list with
     * per-row status and summary"). `total_amount` sums `total_to_pay` across
     * READY rows only — a blocked row's amount is not yet a committed payout.
     *
     * beca_amount/apoyo_amount/pago_iu_amount/difference_amount
     * (sdd/pagos-batch-sede-totals, design D9-D12) are the 5-card money
     * breakdown, also computed over $readyRows only, same scope as
     * total_amount. base_amount is already the nominal, frozen,
     * type-partitioned figure (IU -> monthly_amount, TELMEX_IU ->
     * iu_payment_amount, TELMEX -> 0.00) — no discount/withholding/floor
     * adjustment is applied to cards 1-3 (design's explicit requirement).
     * difference_amount MAY be negative (design D13 — the UI formats the
     * sign, this method only computes the raw value).
     *
     * @param array<int, array{
     *     is_payable: bool,
     *     total_to_pay: string,
     *     snapshot_scholarship_type: string,
     *     base_amount: string,
     *     snapshot_monto_apoyo: ?string,
     * }> $rows
     * @return array{
     *     total: int,
     *     ready: int,
     *     blocking: int,
     *     beca_amount: string,
     *     apoyo_amount: string,
     *     pago_iu_amount: string,
     *     total_amount: string,
     *     difference_amount: string,
     * }
     */
    public function summary(array $rows): array
    {
        $readyRows = array_values(array_filter($rows, fn (array $row) => $row['is_payable']));

        $totalAmount = array_reduce(
            $readyRows,
            fn (float $carry, array $row) => $carry + (float) $row['total_to_pay'],
            0.0
        );

        // Type-filtered nominal sums (design D10/D11). snapshot_scholarship_type
        // is a raw string here — rows() reads it via DB::table(), which
        // bypasses the enum cast — so a direct ->value comparison is correct
        // and no normalization is needed (unlike paidRows(), which sees the
        // cast object).
        $sumByType = fn (string $field, string $type): float => array_reduce(
            $readyRows,
            fn (float $carry, array $row) => $row['snapshot_scholarship_type'] === $type
                ? $carry + (float) ($row[$field] ?? 0)
                : $carry,
            0.0
        );

        $becaAmount   = $sumByType('base_amount', ScholarshipType::IU->value);
        $apoyoAmount  = $sumByType('snapshot_monto_apoyo', ScholarshipType::IU->value);
        $pagoIuAmount = $sumByType('base_amount', ScholarshipType::TELMEX_IU->value);

        return [
            'total'             => count($rows),
            'ready'             => count($readyRows),
            'blocking'          => count($rows) - count($readyRows),
            'beca_amount'       => number_format($becaAmount, 2, '.', ''),
            'apoyo_amount'      => number_format($apoyoAmount, 2, '.', ''),
            'pago_iu_amount'    => number_format($pagoIuAmount, 2, '.', ''),
            'total_amount'      => number_format($totalAmount, 2, '.', ''),
            'difference_amount' => number_format($becaAmount + $apoyoAmount + $pagoIuAmount - $totalAmount, 2, '.', ''),
        ];
    }

    /**
     * Reuses RefrendBulkQueryService's exact existing formula
     * (final_amount + amount_pending_from_previous + refund_amount_from_previous
     * + advance_payment_amount), formatted the same way
     * ScholarshipRefrend::getTotalToPayAttribute() does — as a decimal
     * string, since the driver returns this value as a string on MySQL and
     * a float on SQLite (RefrendBulkQueryService.php documents the same
     * trap).
     *
     * CROSS-REFERENCE (sdd/pago-adelantado, design D6): this formula is
     * mirrored in TWO other places that must stay in lockstep —
     * ScholarshipRefrend::getTotalToPayAttribute() and
     * RefrendBulkQueryService::buildTable()'s row-assembly closure. If a
     * term is added/changed here, add/change it in both. Shared by both
     * rows() and paidRows() — both SELECT lists include advance_payment_amount.
     */
    private function totalToPay(object $row): string
    {
        return number_format(
            (float) $row->final_amount
            + (float) ($row->amount_pending_from_previous ?? 0)
            + (float) ($row->refund_amount_from_previous ?? 0)
            + (float) ($row->advance_payment_amount ?? 0),
            2,
            '.',
            ''
        );
    }
}
