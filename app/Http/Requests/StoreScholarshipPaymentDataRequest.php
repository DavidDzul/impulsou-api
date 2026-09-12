<?php

namespace App\Http\Requests;

use App\Rules\Curp;
use App\Rules\Rfc;
use Illuminate\Foundation\Http\FormRequest;

class StoreScholarshipPaymentDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // unique:scholarship_payment_data,user_id enforces the 1:1
            // constraint (design D1) at the validation layer, on top of the
            // DB-level unique index — a duplicate submit gets a clean 422
            // instead of a DB fatal error (spec R7 "single account" scenario).
            'user_id'        => 'required|integer|exists:users,id|unique:scholarship_payment_data,user_id',
            'bank_name'      => 'required|string|max:100',
            'account_number' => 'required|string|max:20',
            // Curp/Rfc are classic Illuminate\Contracts\Validation\Rule
            // instances (Laravel 8.83 has no invokable-rule support — see
            // App\Rules\Curp/Rfc docblocks), instantiated here, not
            // referenced as bare class strings.
            'curp'           => ['required', new Curp()],
            'rfc'            => ['nullable', new Rfc()],
        ];
    }
}
