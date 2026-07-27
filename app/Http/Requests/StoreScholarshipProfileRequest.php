<?php

namespace App\Http\Requests;

use App\Enums\ScholarshipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScholarshipProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'                    => 'required|exists:users,id|unique:scholarship_profiles,user_id',
            'scholarship_type'           => ['required', Rule::in(array_column(ScholarshipType::cases(), 'value'))],
            'monthly_amount'             => 'required|numeric|min:0',
            'monto_apoyo'                => 'nullable|numeric|min:0|max:99999.99',
            'active_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_valid_until'       => 'nullable|date|required_with:active_discount_percentage',
            'discount_reason'            => 'nullable|string|max:200',
        ];
    }
}
