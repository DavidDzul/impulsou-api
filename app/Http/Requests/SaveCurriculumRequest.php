<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveCurriculumRequest extends FormRequest
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
            "first_name" => "required",
            "last_name" => "required",
            "email" => "required",
            "day_birth" => "required",
            "month_birth" => "required",
            "year_birth" => "required",
            "phone_num" => "required",
            "country" => "required",
            "state" => "required",
            "locality" => "required",
            "professional_summary" => "required",
        ];
    }
}
