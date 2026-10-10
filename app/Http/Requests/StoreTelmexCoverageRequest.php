<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/telmex-cobertura-iu, tasks 3b.1. Authorization is enforced by the
 * route-level `permission:ADM_MANAGE_TELMEX_COVERAGE` middleware — the
 * domain-level guards (scholarship type, UNIQUE user_id, zero amount) live
 * in ActivateTelmexCoverageAction and surface as 422 DomainException, not
 * validation rules here.
 */
class StoreTelmexCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'      => 'required|integer|exists:users,id',
            'start_period' => 'required|date',
            'notes'        => 'nullable|string|max:500',
        ];
    }
}
