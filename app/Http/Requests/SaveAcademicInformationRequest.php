<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAcademicInformationRequest extends FormRequest
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
            "postgraduate_name" => "required",
            "institute_name" => "required",
            "postgraduate_start_date" => "required",
            "postgraduate_end_date" => "required",
            "highlight" => "required",
        ];
    }
}