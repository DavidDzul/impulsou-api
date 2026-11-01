<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/catalogo-bancos R7. Authorization is enforced by the route-level
 * `permission:ADM_PROCESS_PAYMENTS` middleware. A missing transfer_paid_at
 * defaults to today inside MarkManualTransferPaidAction.
 */
class MarkManualTransferPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transfer_reference' => 'required|string|max:50',
            'transfer_paid_at'   => 'nullable|date|before_or_equal:today',
            'transfer_notes'     => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'transfer_reference.required'     => 'La referencia de la transferencia es obligatoria.',
            'transfer_paid_at.before_or_equal' => 'La fecha de pago no puede ser futura.',
        ];
    }
}
