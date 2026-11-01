<?php

namespace Tests\Unit;

use App\Support\Banking\Clabe;
use PHPUnit\Framework\TestCase;

class ClabeTest extends TestCase
{
    /** @test */
    public function it_accepts_a_clabe_with_a_valid_check_digit(): void
    {
        $this->assertTrue(Clabe::isValid('012180000112345671'));
        $this->assertTrue(Clabe::isValid('072000000000000009'));
    }

    /** @test */
    public function it_rejects_a_wrong_check_digit(): void
    {
        $this->assertFalse(Clabe::isValid('012180000112345670'));
    }

    /** @test */
    public function it_rejects_wrong_length_or_non_digits(): void
    {
        $this->assertFalse(Clabe::isValid('01218000011234567'));
        $this->assertFalse(Clabe::isValid('0121800001123456711'));
        $this->assertFalse(Clabe::isValid('01218000011234567A'));
        $this->assertFalse(Clabe::isValid(''));
        $this->assertFalse(Clabe::isValid(null));
    }

    /** @test */
    public function it_extracts_the_bank_code_prefix(): void
    {
        $this->assertSame('012', Clabe::bankCode('012180000112345671'));
        $this->assertSame('072', Clabe::bankCode('072000000000000009'));
        $this->assertNull(Clabe::bankCode('12'));
    }
}
