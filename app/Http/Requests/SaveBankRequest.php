<?php

namespace App\Http\Requests;

use App\Models\Bank;
use Illuminate\Foundation\Http\FormRequest;

/**
 * sdd/catalogo-bancos PR1: shared validation for creating and editing a bank.
 * Authorization is enforced by the route-level `permission:ADM_MANAGE_BANKS`
 * middleware. The system-bank lock lives in the controller because it is a
 * domain rule with its own message, not a field error.
 */
class SaveBankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeBank = $this->route('bank');
        $ignoreId = $routeBank instanceof Bank ? $routeBank->id : $routeBank;

        return [
            'code' => ['required', 'string', 'regex:/^\d{3}$/', function ($attribute, $value, $fail) use ($ignoreId) {
                $query = Bank::where('code', $value);
                if ($ignoreId) {
                    $query->where('id', '!=', $ignoreId);
                }
                if ($query->exists()) {
                    $fail('El código ya está registrado.');
                }
            }],
            'name' => ['required', 'string', 'max:100', function ($attribute, $value, $fail) use ($ignoreId) {
                $query = Bank::whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))]);
                if ($ignoreId) {
                    $query->where('id', '!=', $ignoreId);
                }
                if ($query->exists()) {
                    $fail('El nombre ya está registrado.');
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'El código es obligatorio.',
            'code.regex' => 'El código debe tener exactamente 3 dígitos.',
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede tener más de 100 caracteres.',
            'is_active.boolean' => 'El estado no es válido.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
