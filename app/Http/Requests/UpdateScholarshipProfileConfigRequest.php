<?php

namespace App\Http\Requests;

use App\Enums\ScholarshipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScholarshipProfileConfigRequest extends FormRequest
{
    /**
     * Authorization for this endpoint is enforced by the route-level
     * `permission:ADM_EDIT_SCHOLARSHIP_PROFILE` middleware (design D2),
     * matching UpdateScholarshipProfileRequest's own pattern.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scholarship_type'         => ['required', Rule::in(array_column(ScholarshipType::cases(), 'value'))],
            'monthly_amount'           => 'required|numeric|min:0',
            'monto_apoyo'              => 'required|numeric|min:0|max:99999.99',
            'advance_payment_eligible' => 'sometimes|boolean',
        ];
    }
}
