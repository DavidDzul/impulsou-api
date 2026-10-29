<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/revertir-pago-adelantado (spec R2). Authorization is enforced by the
 * route group (auth:sanctum + user_type:ADMIN), same gate as recording.
 */
class RevertAdvancePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:10|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'El motivo de la reversión es obligatorio.',
            'reason.min'      => 'El motivo debe tener al menos 10 caracteres.',
            'reason.max'      => 'El motivo no puede exceder 500 caracteres.',
        ];
    }
}
