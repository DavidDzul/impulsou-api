<?php

namespace App\Http\Requests;

use App\Rules\Curp;
use App\Rules\Rfc;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScholarshipPaymentDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Design D2: partial updates are allowed (fields not sent are
            // left untouched), but a field that IS sent must still satisfy
            // "required" semantics — `sometimes|required` means "validate
            // only when present, and it may not be empty when it is".
            // `user_id` is intentionally absent — it is never part of the
            // update payload (route-bound via {userId}).
            'bank_name'      => 'sometimes|required|string|max:100',
            'account_number' => 'sometimes|required|string|max:20',
            'curp'           => ['sometimes', 'required', new Curp()],
            // rfc stays nullable even when present, so a client can
            // explicitly clear it by sending `rfc: null`.
            'rfc'            => ['sometimes', 'nullable', new Rfc()],
        ];
    }
}
