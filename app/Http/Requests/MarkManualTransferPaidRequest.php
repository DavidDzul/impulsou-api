<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/catalogo-bancos R7, reshaped by sdd/bank-file-split-other-banks-cheques
 * PR2b: one reference per concept (Beca / Apoyo) plus the amounts the client
 * saw, so a stale split is rejected. Which references are required depends on
 * the server-computed amounts and is enforced by MarkManualTransferPaidAction.
 * Authorization is enforced by the route-level `permission:ADM_PROCESS_PAYMENTS`
 * middleware. A missing paid_at defaults to today.
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
            'references'             => 'nullable|array',
            'references.beca'        => 'nullable|string|max:50',
            'references.apoyo'       => 'nullable|string|max:50',
            'expected_amounts'       => 'required|array',
            'expected_amounts.beca'  => 'required|numeric|min:0',
            'expected_amounts.apoyo' => 'required|numeric|min:0',
            'paid_at'                => 'nullable|date|before_or_equal:today',
            'notes'                  => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'paid_at.before_or_equal' => 'La fecha de pago no puede ser futura.',
        ];
    }
}
