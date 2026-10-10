<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/telmex-cobertura-iu, tasks 3b.1. Reverts a single repayment; the
 * reason is mandatory. Authorization enforced by the route-level
 * `permission:ADM_MANAGE_TELMEX_REPAYMENTS` middleware.
 */
class VoidTelmexCoveragePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'void_reason' => 'required|string|max:500',
        ];
    }
}
