<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InlineUpdateScholarshipRefrendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'atencion_labels'        => 'sometimes|nullable|array',
            'atencion_labels.*'      => 'string|max:100',
            'atencion_observations'  => 'sometimes|nullable|string|max:2000',
            'pedagogia_observations' => 'sometimes|nullable|string|max:2000',
            'notification_method'    => 'sometimes|nullable|string|max:50',
            'notified_at'            => 'sometimes|nullable|date',
            'final_amount_override'  => 'sometimes|nullable|numeric|min:0',
        ];
    }
}
