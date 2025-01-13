<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
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
            "id" => "required|exists:users,id",
            "first_name" => "required|string|max:255",
            "last_name" => "required|string|max:255",
            'email' => 'required|email|max:255|unique:users,email,' . $this->id,
            "password" => "nullable|min:6", // Ahora es opcional
            "phone" => "required|string|max:10",
        ];
    }
}