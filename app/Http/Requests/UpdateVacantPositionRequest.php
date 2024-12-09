<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVacantPositionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            "id" => "required",
            "vacant_name" => "required",
            "activities" => "required",
            "study_profile" => "required",
            "start_day" => "required",
            "end_day" => "required",
            "start_hour" => "required",
            "end_hour" => "required",
            "skills" => "required",
            "contact_name" => "required",
            "contact_position" => "required",
            "contact_telphone" => "required",
            "contact_email" => "required",

            "financial_support" => "nullable|boolean",
            "saturday_hour" => "nullable|boolean",
            "experience" => "nullable|boolean",
            "software_use" => "nullable|boolean",
            "general_knowledge" => "nullable|boolean",
            "employment_contract" => "nullable|boolean",
            "vacation" => "nullable|boolean",
            "christmas_bonus" => "nullable|boolean",
            "social_security" => "nullable|boolean",
            "vacation_bonus" => "nullable|boolean",
            "grocery_vouchers" => "nullable|boolean",
            "savings_fund" => "nullable|boolean",
            "life_insurance" => "nullable|boolean",
            "medical_expenses" => "nullable|boolean",
            "day_off" => "nullable|boolean",
            "sunday_bonus" => "nullable|boolean",
            "paternity_leave" => "nullable|boolean",
            "transportation_help" => "nullable|boolean",
            "productivity_bonus" => "nullable|boolean",
            "automobile" => "nullable|boolean",
            "dining_room" => "nullable|boolean",
            "loans" => "nullable|boolean",
            "other" => "nullable|boolean",
        ];
    }
}
