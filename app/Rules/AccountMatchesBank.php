<?php

namespace App\Rules;

use App\Models\Bank;
use App\Support\Banking\Clabe;
use Illuminate\Contracts\Validation\Rule;

/**
 * sdd/catalogo-bancos R3: the account format depends on the selected bank.
 * BBVA (batch bank) takes a 9-10 digit account; every other bank takes an
 * 18-digit CLABE with a valid check digit whose prefix is the bank's code.
 *
 * Classic Rule contract (Laravel 8.83 has no invokable rules).
 */
class AccountMatchesBank implements Rule
{
    private string $message = 'La cuenta no es válida.';

    public function __construct(private Bank $bank)
    {
    }

    public function passes($attribute, $value): bool
    {
        $account = is_string($value) ? $value : '';

        if ($this->bank->code === Bank::BATCH_BANK_CODE) {
            $this->message = 'La cuenta debe tener 9 o 10 dígitos.';

            return preg_match('/^\d{9,10}\z/', $account) === 1;
        }

        if (!Clabe::isValid($account)) {
            $this->message = 'La CLABE no es válida.';

            return false;
        }

        if (Clabe::bankCode($account) !== $this->bank->code) {
            $this->message = "La CLABE no corresponde a {$this->bank->name}.";

            return false;
        }

        return true;
    }

    public function message(): string
    {
        return $this->message;
    }
}
