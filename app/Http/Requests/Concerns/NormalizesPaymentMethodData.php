<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PaymentMethod;
use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Rules\AccountMatchesBank;
use Illuminate\Validation\Validator;

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
            $validated['bank_id']        = null;
            $validated['bank_name']      = null;
            $validated['account_number'] = null;
        } else {
            $validated['cheque_beneficiary'] = null;

            // bank_name is a server-side snapshot of the selected bank, never
            // client input (kept one release for legacy display).
            if (!empty($validated['bank_id'])) {
                $validated['bank_name'] = Bank::find($validated['bank_id'])?->name;
            }
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
            'bank_id'        => 'El banco es obligatorio para transferencias.',
            'account_number' => 'El número de cuenta es obligatorio para transferencias.',
        ];
    }

    /**
     * sdd/catalogo-bancos R3: TRANSFER needs a selectable bank and an account
     * that matches it. `$existing` is the stored row on updates (null on create).
     * The account is re-checked only when bank, account or method is part of the
     * request, so unrelated edits never trip over legacy data.
     */
    protected function validateTransferBank(Validator $validator, ?ScholarshipPaymentData $existing): void
    {
        $errors  = $validator->errors();
        $touched = $this->has('bank_id') || $this->has('account_number') || $this->has('payment_method');
        $bankId = $this->has('bank_id') ? $this->input('bank_id') : $existing?->bank_id;

        // Legacy rows without a bank stay editable for unrelated fields.
        if (($bankId === null || $bankId === '') && ($touched || $existing === null)) {
            $errors->add('bank_id', $this->transferRequiredMessages()['bank_id']);
        }

        $account = $this->has('account_number') ? $this->input('account_number') : $existing?->account_number;

        if ($account === null || $account === '') {
            $errors->add('account_number', $this->transferRequiredMessages()['account_number']);
        }

        $bank = ($bankId !== null && $bankId !== '' && !$errors->has('bank_id')) ? Bank::find($bankId) : null;

        if ($bank === null) {
            return;
        }

        if ((int) $bank->id !== (int) $existing?->bank_id && !$bank->is_active) {
            $errors->add('bank_id', 'El banco seleccionado no está disponible.');

            return;
        }

        if ($touched && !$errors->has('account_number')) {
            $rule = new AccountMatchesBank($bank);

            if (!$rule->passes('account_number', $account)) {
                $errors->add('account_number', $rule->message());
            }
        }
    }
}
