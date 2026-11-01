<?php

namespace Tests\Unit;

use App\Models\Bank;
use App\Rules\AccountMatchesBank;
use PHPUnit\Framework\TestCase;

class AccountMatchesBankTest extends TestCase
{
    private function bank(string $code, string $name): Bank
    {
        $bank = new Bank();
        $bank->code = $code;
        $bank->name = $name;

        return $bank;
    }

    /** @test */
    public function bbva_accepts_9_and_10_digits_only(): void
    {
        $rule = new AccountMatchesBank($this->bank('012', 'BBVA'));

        $this->assertTrue($rule->passes('account_number', '123456789'));
        $this->assertTrue($rule->passes('account_number', '1234567890'));
        $this->assertFalse($rule->passes('account_number', '12345678'));
        $this->assertFalse($rule->passes('account_number', '012180000112345671'));
        $this->assertSame('La cuenta debe tener 9 o 10 dígitos.', $rule->message());
    }

    /** @test */
    public function other_banks_require_a_valid_clabe_with_their_prefix(): void
    {
        $rule = new AccountMatchesBank($this->bank('072', 'Banorte'));

        $this->assertTrue($rule->passes('account_number', '072000000000000009'));

        $this->assertFalse($rule->passes('account_number', '1234567890'));
        $this->assertSame('La CLABE no es válida.', $rule->message());

        $this->assertFalse($rule->passes('account_number', '012180000112345671'));
        $this->assertSame('La CLABE no corresponde a Banorte.', $rule->message());
    }

    /** @test */
    public function non_string_values_fail(): void
    {
        $rule = new AccountMatchesBank($this->bank('012', 'BBVA'));

        $this->assertFalse($rule->passes('account_number', null));
    }
}
