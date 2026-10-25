<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/pago-cheque PR3 (spec R3.1). Authorization is enforced by the
 * route-level `permission:ADM_PROCESS_PAYMENTS` middleware. A missing
 * cheque_delivered_at defaults to today inside MarkChequePaidAction.
 */
class MarkChequePaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cheque_number'       => 'required|string|max:50',
            'cheque_delivered_at' => 'nullable|date|before_or_equal:today',
            'cheque_notes'        => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'cheque_number.required'              => 'El número de cheque es obligatorio.',
            'cheque_delivered_at.before_or_equal' => 'La fecha de entrega no puede ser futura.',
        ];
    }
}
