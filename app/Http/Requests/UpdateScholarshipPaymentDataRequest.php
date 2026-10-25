<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\NormalizesPaymentMethodData;
use App\Models\ScholarshipPaymentData;
use App\Rules\Curp;
use App\Rules\Rfc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateScholarshipPaymentDataRequest extends FormRequest
{
    use NormalizesPaymentMethodData;

    private ?ScholarshipPaymentData $existing = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Design D2: partial updates are allowed (fields not sent are
            // left untouched), but a field that IS sent must still satisfy
            // "required" semantics — `sometimes|required` means "validate
            // only when present, and it may not be empty when it is".
            // `user_id` is intentionally absent — it is never part of the
            // update payload (route-bound via {userId}).
            'payment_method' => ['sometimes', 'required', Rule::in(PaymentMethod::values())],
            // Presence for TRANSFER depends on the stored row, so it is
            // enforced in withValidator() (sdd/pago-cheque R1.3).
            'bank_name'      => 'sometimes|nullable|string|max:100',
            'account_number' => 'sometimes|nullable|string|max:20',
            'cheque_beneficiary' => 'sometimes|nullable|string|max:150',
            'curp'           => ['sometimes', 'required', new Curp()],
            // rfc stays nullable even when present, so a client can
            // explicitly clear it by sending `rfc: null`.
            'rfc'            => ['sometimes', 'nullable', new Rfc()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->effectiveMethod() !== PaymentMethod::TRANSFER) {
                return;
            }

            // A TRANSFER row must end up with bank data: the sent value
            // wins, otherwise the stored one must exist (CHEQUE -> TRANSFER).
            foreach (['bank_name', 'account_number'] as $field) {
                $value = $this->has($field) ? $this->input($field) : $this->existing()?->{$field};

                if ($value === null || $value === '') {
                    $validator->errors()->add($field, "El campo {$field} es obligatorio para transferencias.");
                }
            }
        });
    }

    /**
     * Validated data ready to persist (spec R1.5 invariants applied).
     */
    public function validatedForPersistence(): array
    {
        return $this->normalizeForMethod($this->validated(), $this->effectiveMethod());
    }

    private function existing(): ?ScholarshipPaymentData
    {
        return $this->existing ??= ScholarshipPaymentData::where('user_id', $this->route('userId'))->first();
    }

    /**
     * Spec R1.1: when payment_method is omitted the stored one is kept.
     */
    private function effectiveMethod(): PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $this->input('payment_method'))
            ?? $this->existing()?->payment_method
            ?? PaymentMethod::TRANSFER;
    }
}
