<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/pago-cheque PR3 (spec R3.1), reshaped by
 * sdd/bank-file-split-other-banks-cheques PR2a: one reference per concept
 * (Beca / Apoyo) plus the amounts the client saw, so a stale split is
 * rejected. Which references are required depends on the server-computed
 * amounts and is enforced by MarkChequePaidAction. Authorization is enforced
 * by the route-level `permission:ADM_PROCESS_PAYMENTS` middleware. A missing
 * paid_at defaults to today.
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
            'references'              => 'nullable|array',
            'references.beca'         => 'nullable|string|max:50',
            'references.apoyo'        => 'nullable|string|max:50',
            'expected_amounts'        => 'required|array',
            'expected_amounts.beca'   => 'required|numeric|min:0',
            'expected_amounts.apoyo'  => 'required|numeric|min:0',
            'paid_at'                 => 'nullable|date|before_or_equal:today',
            'notes'                   => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'paid_at.before_or_equal' => 'La fecha de entrega no puede ser futura.',
        ];
    }
}
