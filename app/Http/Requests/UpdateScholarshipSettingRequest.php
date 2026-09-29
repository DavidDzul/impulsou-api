<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateScholarshipSettingRequest extends FormRequest
{
    /**
     * Authorization is enforced by the route-level
     * `permission:ADM_MANAGE_SCHOLARSHIP_SETTINGS` middleware (design D8).
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'telmex_base_amount' => 'required|numeric|min:0|max:99999.99',
        ];
    }
}
