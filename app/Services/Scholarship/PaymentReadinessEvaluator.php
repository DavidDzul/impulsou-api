<?php

namespace App\Services\Scholarship;

use App\Enums\PaymentMethod;
use App\Support\Scholarship\RefrendPayableTotal;
use App\Support\Scholarship\TelmexPaymentPolicy;
use BackedEnum;

/**
 * Single source of truth for payment-readiness blocking reasons.
 *
 * Consumed by PaymentBatchService::rows() (pre-payment review) and
 * BulkPayAction (payment-time re-check under the row lock) so both call
 * sites share identical rules — never two parallel implementations (design
 * brief requirement, sdd/becario-payment-file-generation/design).
 *
 * Absorbs the WITHHELD && nothing-to-pay rule that previously lived inline
 * in ScholarshipRefrendController::bulkPay() (lines 562-594). Nothing to pay
 * means final_amount + pending + advance <= 0 (RefrendPayableTotal).
 *
 * PR3 (sdd/becario-payment-bank-file-export/design D2) injects
 * BankDataValidator so account-number/RFC structural rules are enforced
 * exactly once and shared with the export-time gate — never a second,
 * parallel implementation of what makes an account/RFC valid.
 */
class PaymentReadinessEvaluator
{
    public function __construct(private BankDataValidator $bankDataValidator)
    {
    }

    /**
     * @param object $refrendRow Duck-typed row exposing workflow_status,
     *                           locked_at, payment_batch_id, status, and
     *                           final_amount (amount_pending_from_previous and
     *                           advance_payment_amount are optional, null-safe).
     *                           Works with a stdClass from a
     *                           DB::table() row or an Eloquent
     *                           ScholarshipRefrend model (enum-cast
     *                           attributes are unwrapped via statusValue()).
     * @param string|null $accountNumber Required, non-defaulted (design D2):
     *                                   a caller that forgets to pass bank
     *                                   data throws loudly rather than
     *                                   silently skipping the check.
     * @param string|null $rfc Required, non-defaulted — see $accountNumber.
     * @param PaymentMethod $paymentMethod sdd/pago-cheque D7: defaults to
     *                                    TRANSFER so a forgotten caller still
     *                                    runs the bank checks (fails closed).
     *                                    CHEQUE skips BankDataValidator only;
     *                                    every other rule still applies.
     * @return array{is_payable: bool, blocking_reasons: array<int, array{code: string, message: string}>}
     */
    public function evaluate(
        object $refrendRow,
        bool $hasEnrollment,
        bool $hasPaymentData,
        ?string $accountNumber,
        ?string $rfc,
        PaymentMethod $paymentMethod = PaymentMethod::TRANSFER
    ): array {
        $reasons = [];

        $alreadyPaid = $refrendRow->locked_at !== null || ($refrendRow->payment_batch_id ?? null) !== null;

        // A refrend that already finished its lifecycle (paid, locked) can no
        // longer be "pending approval" — the two reasons are mutually
        // exclusive in practice, and showing both together is misleading.
        if (!$alreadyPaid && $this->statusValue($refrendRow->workflow_status) !== 'LISTO_PARA_PAGO') {
            $reasons[] = ['code' => 'NOT_APPROVED', 'message' => 'Pendiente de aprobación'];
        }

        if ($alreadyPaid) {
            $reasons[] = ['code' => 'ALREADY_PAID', 'message' => 'Pago ya realizado'];
        }

        if (!$hasEnrollment) {
            $reasons[] = ['code' => 'MISSING_ENROLLMENT', 'message' => 'Sin matrícula registrada'];
        }

        if (!$hasPaymentData) {
            $reasons[] = ['code' => 'MISSING_PAYMENT_DATA', 'message' => 'Sin datos de pago registrados'];
        }

        // Nothing to pay means the REAL payable total is <= 0
        // (final_amount + pending + advance, via the shared helper) — a
        // WITHHELD row with final_amount = 0 can still owe pending/advance
        // money (sdd/telmex-pendiente-lote).
        $payableTotal = RefrendPayableTotal::of($refrendRow);

        if ($this->statusValue($refrendRow->status) === 'WITHHELD' && (float) $payableTotal <= 0) {
            $reasons[] = ['code' => 'NOTHING_TO_PAY', 'message' => 'Monto retenido sin saldo por pagar'];
        }

        // Filter A guard (sdd/scholarship-telmex-iu-split, design D1/D3;
        // extended by sdd/telmex-cobertura-iu with the coverage arg): mirrors
        // PaymentBatchService::rows()'s SQL-level exclusion for the
        // BulkPayAction re-check under the row lock. `??` null-coalescing
        // keeps this evaluator safe for any caller/fixture that omits these
        // five columns (e.g. existing tests built before this change).
        // Owed money (pending + advance, sdd/telmex-pendiente-lote) also
        // makes a pure TELMEX row a candidate.
        if (!TelmexPaymentPolicy::isBatchCandidate(
            $refrendRow->snapshot_scholarship_type ?? null,
            $refrendRow->snapshot_temporary_increase_amount ?? null,
            $refrendRow->snapshot_telmex_coverage_id ?? null,
            $refrendRow->amount_pending_from_previous ?? null,
            $refrendRow->advance_payment_amount ?? null
        )) {
            $reasons[] = ['code' => 'TELMEX_NOT_PAYABLE', 'message' => 'Beca Telmex sin aumento temporal, cobertura IU ni saldo pendiente'];
        }

        // Bank-data structural checks (account number, RFC) are delegated to
        // BankDataValidator (PR1/PR2, sdd/becario-payment-bank-file-export)
        // so this evaluator and the export-time gate share one rule set.
        // BankDataValidator also validates the payable amount, but that rule
        // is intentionally NOT merged in here: this evaluator already owns a
        // narrower, WITHHELD-specific NOTHING_TO_PAY check above. The shared
        // RefrendPayableTotal helper (the single home of the money formula)
        // feeds the validator so the amount check runs against real data, but
        // its NOTHING_TO_PAY reason (if any) is discarded below so there is
        // never a second, conflicting source of truth for that code.
        if ($paymentMethod === PaymentMethod::CHEQUE) {
            return [
                'is_payable'       => empty($reasons),
                'blocking_reasons' => $reasons,
            ];
        }

        foreach ($this->bankDataValidator->validate($accountNumber, $rfc, $payableTotal) as $bankDataReason) {
            if ($bankDataReason['code'] === 'NOTHING_TO_PAY') {
                continue;
            }

            $reasons[] = $bankDataReason;
        }

        return [
            'is_payable'       => empty($reasons),
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Unwraps a BackedEnum (e.g. Eloquent's RefrendStatus cast) to its
     * scalar value; passes plain strings (DB::table() rows) through as-is.
     */
    private function statusValue(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
