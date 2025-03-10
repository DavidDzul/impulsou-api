<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessInformationRequest extends FormRequest
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
            "bs_name" => "required",
            "bs_director" => "required",
            "bs_rfc" => "required",
            "bs_country" => "required",
            "bs_state" => "required",
            "bs_locality" => "required",
            "bs_adrress" => "required",
            "bs_telphone" => "required",
            "bs_line" => "required",
            "bs_description" => "required",
            "bs_website" => "nullable",
        ];
    }
}
