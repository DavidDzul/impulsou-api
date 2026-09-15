<?php

namespace App\Services\Scholarship;

use BackedEnum;

/**
 * Single source of truth for payment-readiness blocking reasons.
 *
 * Consumed by PaymentBatchService::rows() (pre-payment review) and
 * BulkPayAction (payment-time re-check under the row lock) so both call
 * sites share identical rules — never two parallel implementations (design
 * brief requirement, sdd/becario-payment-file-generation/design).
 *
 * Absorbs the WITHHELD && final_amount <= 0 rule that previously lived
 * inline in ScholarshipRefrendController::bulkPay() (lines 562-594).
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
     *                           final_amount. Works with a stdClass from a
     *                           DB::table() row or an Eloquent
     *                           ScholarshipRefrend model (enum-cast
     *                           attributes are unwrapped via statusValue()).
     * @param string|null $accountNumber Required, non-defaulted (design D2):
     *                                   a caller that forgets to pass bank
     *                                   data throws loudly rather than
     *                                   silently skipping the check.
     * @param string|null $rfc Required, non-defaulted — see $accountNumber.
     * @return array{is_payable: bool, blocking_reasons: array<int, array{code: string, message: string}>}
     */
    public function evaluate(
        object $refrendRow,
        bool $hasEnrollment,
        bool $hasPaymentData,
        ?string $accountNumber,
        ?string $rfc
    ): array {
        $reasons = [];

        if ($this->statusValue($refrendRow->workflow_status) !== 'LISTO_PARA_PAGO') {
            $reasons[] = ['code' => 'NOT_APPROVED', 'message' => 'Pendiente de aprobación'];
        }

        if ($refrendRow->locked_at !== null || ($refrendRow->payment_batch_id ?? null) !== null) {
            $reasons[] = ['code' => 'ALREADY_PAID', 'message' => 'Ya fue procesado en un pago anterior'];
        }

        if (!$hasEnrollment) {
            $reasons[] = ['code' => 'MISSING_ENROLLMENT', 'message' => 'Sin matrícula registrada'];
        }

        if (!$hasPaymentData) {
            $reasons[] = ['code' => 'MISSING_PAYMENT_DATA', 'message' => 'Sin cuenta bancaria registrada'];
        }

        if ($this->statusValue($refrendRow->status) === 'WITHHELD' && (float) $refrendRow->final_amount <= 0) {
            $reasons[] = ['code' => 'NOTHING_TO_PAY', 'message' => 'Monto retenido sin saldo por pagar'];
        }

        // Bank-data structural checks (account number, RFC) are delegated to
        // BankDataValidator (PR1/PR2, sdd/becario-payment-bank-file-export)
        // so this evaluator and the export-time gate share one rule set.
        // BankDataValidator also validates the payable amount, but that rule
        // is intentionally NOT merged in here: this evaluator already owns a
        // narrower, WITHHELD-specific NOTHING_TO_PAY check above, and
        // reusing BankDataValidator's blanket amount>0 rule would require
        // duplicating PaymentBatchService::totalToPay()'s formula just to
        // feed it — the one thing the design forbids (D1, "no parallel
        // implementations" of the money formula). $refrendRow->final_amount
        // is passed so the amount check runs against real data, but its
        // NOTHING_TO_PAY reason (if any) is discarded below so there is
        // never a second, conflicting source of truth for that code.
        foreach ($this->bankDataValidator->validate($accountNumber, $rfc, (string) $refrendRow->final_amount) as $bankDataReason) {
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
