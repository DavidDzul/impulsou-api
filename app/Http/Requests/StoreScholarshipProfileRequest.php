<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScholarshipProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id|unique:scholarship_profiles,user_id',
            // sdd/scholarship-profile-config-to-admin design D3: these 4
            // fields moved to the admin-only
            // PUT scholarship-profiles/{userId}/config endpoint. psicol-panel
            // must not write them via this shared store endpoint — `prohibited`
            // surfaces a named-field 422 instead of silently stripping the
            // value (design D3 rationale).
            'scholarship_type'           => 'prohibited',
            'monthly_amount'             => 'prohibited',
            'monto_apoyo'                => 'prohibited',
            'advance_payment_eligible'   => 'prohibited',
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
