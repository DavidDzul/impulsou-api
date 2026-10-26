<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/pago-cheque PR4 (spec R4.1). Authorization is enforced by the
 * route-level `permission:ADM_PROCESS_PAYMENTS` middleware.
 */
class RevertChequePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
        ];
    }
}
