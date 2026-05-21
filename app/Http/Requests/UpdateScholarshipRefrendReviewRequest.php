<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateScholarshipRefrendReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'observations' => 'nullable|string|max:2000',
            'labels'       => 'nullable|array',
            'labels.*'     => 'string|max:100',
        ];
    }
}
