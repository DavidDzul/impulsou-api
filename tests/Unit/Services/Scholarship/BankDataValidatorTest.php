<?php

namespace Tests\Unit\Services\Scholarship;

use App\Services\Scholarship\BankDataValidator;
use Tests\TestCase;

/**
 * Unit tests for BankDataValidator — the single rule set for BBVA bank-file
 * data, mirroring the bank's own macro `ValidateRow` logic (design D2,
 * sdd/becario-payment-bank-file-export/design). Consumed by both
 * PaymentReadinessEvaluator (payment-time, PR3) and the export path
 * (export-time, PR3/PR4). Pure — no DB, no framework.
 */
class BankDataValidatorTest extends TestCase
{
    private BankDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new BankDataValidator();
    }

    // ── all-clear ───────────────────────────────────────────────────────

    /** @test */
    public function all_clear_row_returns_no_reasons(): void
    {
        $reasons = $this->validator->validate('0123456789', 'PEPJ800101ABC', '1200.00');

        $this->assertSame([], $reasons);
    }

    // ── account number ──────────────────────────────────────────────────

    /** @test */
    public function nine_digit_account_number_is_valid(): void
    {
        $reasons = $this->validator->validate('123456789', null, '100.00');

        $this->assertSame([], $reasons);
    }

    /** @test */
    public function ten_digit_account_number_is_valid(): void
    {
        $reasons = $this->validator->validate('0123456789', null, '100.00');

        $this->assertSame([], $reasons);
    }

    /** @test */
    public function eight_digit_account_number_is_invalid(): void
    {
        $reasons = $this->validator->validate('12345678', null, '100.00');

        $this->assertSame(
            [['code' => 'INVALID_ACCOUNT_NUMBER', 'message' => 'Número de cuenta inválido: debe ser numérico de 9 o 10 dígitos']],
            $reasons
        );
    }

    /** @test */
    public function eleven_digit_account_number_is_invalid(): void
    {
        $reasons = $this->validator->validate('01234567891', null, '100.00');

        $this->assertSame('INVALID_ACCOUNT_NUMBER', $reasons[0]['code']);
    }

    /** @test */
    public function non_numeric_account_number_is_invalid(): void
    {
        $reasons = $this->validator->validate('12345678A', null, '100.00');

        $this->assertSame('INVALID_ACCOUNT_NUMBER', $reasons[0]['code']);
    }

    /** @test */
    public function null_account_number_is_invalid(): void
    {
        $reasons = $this->validator->validate(null, null, '100.00');

        $this->assertSame('INVALID_ACCOUNT_NUMBER', $reasons[0]['code']);
    }

    // ── amount ───────────────────────────────────────────────────────────

    /** @test */
    public function positive_amount_is_valid(): void
    {
        $reasons = $this->validator->validate('0123456789', null, '0.01');

        $this->assertSame([], $reasons);
    }

    /** @test */
    public function zero_amount_is_invalid(): void
    {
        $reasons = $this->validator->validate('0123456789', null, '0.00');

        $this->assertSame(
            [['code' => 'NOTHING_TO_PAY', 'message' => 'Monto a pagar menor o igual a cero']],
            $reasons
        );
    }

    /** @test */
    public function negative_amount_is_invalid(): void
    {
        $reasons = $this->validator->validate('0123456789', null, '-50.00');

        $this->assertSame('NOTHING_TO_PAY', $reasons[0]['code']);
    }

    /** @test */
    public function non_numeric_amount_is_invalid(): void
    {
        $reasons = $this->validator->validate('0123456789', null, 'abc');

        $this->assertSame('NOTHING_TO_PAY', $reasons[0]['code']);
    }

    // ── RFC ──────────────────────────────────────────────────────────────

    /** @test */
    public function blank_rfc_is_valid(): void
    {
        $reasons = $this->validator->validate('0123456789', null, '100.00');

        $this->assertSame([], $reasons);
    }

    /** @test */
    public function empty_string_rfc_is_valid(): void
    {
        $reasons = $this->validator->validate('0123456789', '', '100.00');

        $this->assertSame([], $reasons);
    }

    /** @test */
    public function well_formed_rfc_is_valid(): void
    {
        // PEPJ 80 01 01 ABC — realistic RFC: alpha(4) + year(2) + month(01) + day(01) + homoclave
        $reasons = $this->validator->validate('0123456789', 'PEPJ800101ABC', '100.00');

        $this->assertSame([], $reasons);
    }

    /** @test */
    public function rfc_with_invalid_month_is_invalid(): void
    {
        // Month "13" does not exist
        $reasons = $this->validator->validate('0123456789', 'PEPJ801301ABC', '100.00');

        $this->assertSame(
            [['code' => 'INVALID_RFC', 'message' => 'RFC con estructura inválida']],
            $reasons
        );
    }

    /** @test */
    public function rfc_with_invalid_day_is_invalid(): void
    {
        // Day "32" does not exist
        $reasons = $this->validator->validate('0123456789', 'PEPJ800132ABC', '100.00');

        $this->assertSame('INVALID_RFC', $reasons[0]['code']);
    }

    /** @test */
    public function rfc_with_non_alphabetic_first_four_chars_is_invalid(): void
    {
        $reasons = $this->validator->validate('0123456789', '1234800101ABC', '100.00');

        $this->assertSame('INVALID_RFC', $reasons[0]['code']);
    }

    // ── multiple reasons ─────────────────────────────────────────────────

    /** @test */
    public function all_reasons_can_fire_at_once_in_account_rfc_amount_order(): void
    {
        $reasons = $this->validator->validate('12345678A', '1234800101ABC', '0.00');

        $this->assertSame(
            ['INVALID_ACCOUNT_NUMBER', 'INVALID_RFC', 'NOTHING_TO_PAY'],
            array_column($reasons, 'code')
        );
    }
}
