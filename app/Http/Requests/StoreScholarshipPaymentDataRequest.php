<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\NormalizesPaymentMethodData;
use App\Rules\Curp;
use App\Rules\Rfc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScholarshipPaymentDataRequest extends FormRequest
{
    use NormalizesPaymentMethodData;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // sdd/pago-cheque R1.1: payment_method defaults to TRANSFER.
        if (!$this->filled('payment_method')) {
            $this->merge(['payment_method' => PaymentMethod::TRANSFER->value]);
        }
    }

    public function rules(): array
    {
        return [
            // unique:scholarship_payment_data,user_id enforces the 1:1
            // constraint (design D1) at the validation layer, on top of the
            // DB-level unique index — a duplicate submit gets a clean 422
            // instead of a DB fatal error (spec R7 "single account" scenario).
            'user_id'        => 'required|integer|exists:users,id|unique:scholarship_payment_data,user_id',
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            // Bank data is mandatory only for TRANSFER (spec R1.3).
            'bank_name'      => 'required_if:payment_method,TRANSFER|nullable|string|max:100',
            'account_number' => 'required_if:payment_method,TRANSFER|nullable|string|max:20',
            'cheque_beneficiary' => 'nullable|string|max:150',
            // Curp/Rfc are classic Illuminate\Contracts\Validation\Rule
            // instances (Laravel 8.83 has no invokable-rule support — see
            // App\Rules\Curp/Rfc docblocks), instantiated here, not
            // referenced as bare class strings.
            'curp'           => ['required', new Curp()],
            'rfc'            => ['nullable', new Rfc()],
        ];
    }

    /**
     * Validated data ready to persist (spec R1.5 invariants applied).
     */
    public function validatedForPersistence(): array
    {
        $data = $this->validated();

        return $this->normalizeForMethod($data, PaymentMethod::from($data['payment_method']));
    }
}
