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
            'advance_payment_eligible'   => 'sometimes|boolean',
            'active_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_valid_from'        => 'nullable|date|required_with:active_discount_percentage|before_or_equal:discount_valid_until',
            'discount_valid_until'       => 'nullable|date|required_with:active_discount_percentage',
            'discount_reason'            => 'nullable|string|max:200',

            'temporary_increase_amount'      => 'nullable|numeric|min:0.01|max:99999.99|required_with:temporary_increase_valid_from,temporary_increase_valid_until,temporary_increase_reason',
            'temporary_increase_valid_from'  => 'nullable|date|required_with:temporary_increase_amount',
            'temporary_increase_valid_until' => 'nullable|date|after:temporary_increase_valid_from|required_with:temporary_increase_amount',
            'temporary_increase_reason'      => 'nullable|string|max:200|required_with:temporary_increase_amount',
        ];
    }
}
