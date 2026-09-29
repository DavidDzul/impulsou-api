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
            // iu_payment_amount (sdd/scholarship-telmex-iu-split, design
            // Interfaces/Contracts): required only for TELMEX_IU.
            // `exclude_unless` — NOT `prohibited_unless` — so switching a
            // profile away from TELMEX_IU silently drops the field from the
            // validated data (see updateConfig()'s explicit null-clearing)
            // instead of 422-ing a form that still carries a stale value.
            'iu_payment_amount'        => [
                Rule::requiredIf(fn () => $this->input('scholarship_type') === ScholarshipType::TELMEX_IU->value),
                'exclude_unless:scholarship_type,' . ScholarshipType::TELMEX_IU->value,
                'numeric', 'min:0', 'max:99999.99',
            ],
        ];
    }
}
