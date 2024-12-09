<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePracticeVacantRequest extends FormRequest
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
            "vacant_name" => "required",
            "category" => "required",
            "activities" => "required",
            "study_profile" => "required",
            "financial_support" => "required",

            "start_day" => "required",
            "end_day" => "required",
            "start_hour" => "required",
            "end_hour" => "required",
            "semester" => "required",
            "skills" => "required",

            "contact_name" => "required",
            "contact_position" => "required",
            "contact_telphone" => "required",
            "contact_email" => "required",
        ];
    }
}
