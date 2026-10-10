<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/telmex-cobertura-iu, tasks 3b.1. Records a becario's repayment
 * (external deposit, client-answers ca2/ca3). The over-repayment / status
 * guards (EN_COBRO-only per decisions-3 #1926) live in
 * RegisterTelmexCoveragePaymentAction as 422 DomainException, not
 * validation rules here. Authorization enforced by the route-level
 * `permission:ADM_MANAGE_TELMEX_REPAYMENTS` middleware.
 */
class StoreTelmexCoveragePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'    => 'required|numeric|min:0.01',
            'paid_at'   => 'nullable|date',
            'reference' => 'nullable|string|max:100',
            'notes'     => 'nullable|string|max:500',
        ];
    }
}
