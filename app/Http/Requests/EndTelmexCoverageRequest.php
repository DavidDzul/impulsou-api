<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/telmex-cobertura-iu, tasks 3b.1. "Telmex empezó a pagar" — staff
 * submits the first month Telmex itself starts covering; the action derives
 * end_period = telmex_start_period - 1 month. Authorization enforced by the
 * route-level `permission:ADM_MANAGE_TELMEX_COVERAGE` middleware.
 */
class EndTelmexCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'telmex_start_period' => 'required|date',
        ];
    }
}
