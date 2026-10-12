<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/telmex-cobertura-lote, PR1 design D6. Request-shape validation only
 * — an unknown or non-ACTIVA `coverage_id` is a per-item failure reported
 * in the 200 response, never a 422 for the whole batch (no `exists` rule).
 * Authorization is enforced by the route-level
 * `permission:ADM_MANAGE_TELMEX_COVERAGE` middleware.
 */
class EndTelmexCoveragesBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coverage_ids'        => 'required|array|min:1|max:200',
            'coverage_ids.*'      => 'integer|min:1|distinct',
            'telmex_start_period' => 'required|date',
        ];
    }
}
