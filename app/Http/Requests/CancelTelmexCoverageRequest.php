<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/telmex-cobertura-iu, amendment #1918 am1 + tasks 3b.1. The reason is
 * ALWAYS mandatory (10-500 chars), even when nothing was ever paid — there
 * is no separate "CONDONADA" state. Authorization enforced by the
 * route-level `permission:ADM_MANAGE_TELMEX_COVERAGE` middleware.
 */
class CancelTelmexCoverageRequest extends FormRequest
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
}
