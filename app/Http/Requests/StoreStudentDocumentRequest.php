<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id'       => 'required|exists:users,id',
            'document_type' => ['required', Rule::in(array_column(DocumentType::cases(), 'value'))],
            'period_year'   => 'required|integer|min:2020|max:2100',
            'period_month'  => 'required|integer|min:1|max:12',
            'file'          => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ];
    }
}
