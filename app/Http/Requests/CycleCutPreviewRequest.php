<?php

namespace App\Http\Requests;

use App\Enums\CycleClosureReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * sdd/revertir-pago-adelantado, S6 (spec R20, S46). Authorization is enforced
 * by the route group (auth:sanctum + user_type:ADMIN), same gate as `situation`.
 */
class CycleCutPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(CycleClosureReason::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'El tipo de cierre es obligatorio.',
            'reason.in'       => 'El tipo de cierre debe ser BAJA_DEFINITIVA o EGRESADO.',
        ];
    }
}
