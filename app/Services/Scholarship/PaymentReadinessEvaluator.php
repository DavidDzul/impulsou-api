<?php

namespace App\Services\Scholarship;

use BackedEnum;

/**
 * Single source of truth for payment-readiness blocking reasons.
 *
 * Consumed by PaymentBatchService::rows() (pre-payment review) and, in a
 * later PR, by BulkPayAction (payment-time re-check under the row lock) so
 * both call sites share identical rules — never two parallel implementations
 * (design brief requirement, sdd/becario-payment-file-generation/design).
 *
 * Absorbs the WITHHELD && final_amount <= 0 rule that previously lived
 * inline in ScholarshipRefrendController::bulkPay() (lines 562-594).
 */
class PaymentReadinessEvaluator
{
    /**
     * @param object $refrendRow Duck-typed row exposing workflow_status,
     *                           locked_at, payment_batch_id, status, and
     *                           final_amount. Works with a stdClass from a
     *                           DB::table() row or an Eloquent
     *                           ScholarshipRefrend model (enum-cast
     *                           attributes are unwrapped via statusValue()).
     * @return array{is_payable: bool, blocking_reasons: array<int, array{code: string, message: string}>}
     */
    public function evaluate(object $refrendRow, bool $hasEnrollment, bool $hasPaymentData): array
    {
        $reasons = [];

        if ($this->statusValue($refrendRow->workflow_status) !== 'LISTO_PARA_PAGO') {
            $reasons[] = ['code' => 'NOT_APPROVED', 'message' => 'Pendiente de aprobación en psicol-panel'];
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
