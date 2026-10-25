<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PaymentMethod;

/**
 * sdd/pago-cheque, spec R1.5: the server is authoritative on which fields
 * a payment method may carry. CHEQUE rows never keep bank data; TRANSFER
 * rows never keep a cheque beneficiary.
 */
trait NormalizesPaymentMethodData
{
    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function normalizeForMethod(array $validated, PaymentMethod $method): array
    {
        if ($method === PaymentMethod::CHEQUE) {
            $validated['bank_name']      = null;
            $validated['account_number'] = null;
        } else {
            $validated['cheque_beneficiary'] = null;
        }

        return $validated;
    }

    /**
     * Human-readable "required for transfers" messages (no technical field names).
     *
     * @return array<string, string>
     */
    protected function transferRequiredMessages(): array
    {
        return [
            'bank_name'      => 'El banco es obligatorio para transferencias.',
            'account_number' => 'El número de cuenta es obligatorio para transferencias.',
        ];
    }
}
