<?php

namespace Tests\Unit;

use App\Rules\Curp;
use App\Rules\Rfc;
use Tests\TestCase;

/**
 * Covers spec's explicit YAGNI resolution: bounded length + charset only,
 * no RENAPO checksum / structural validation (design D3).
 */
class CurpRfcValidationTest extends TestCase
{
    // ── CURP ─────────────────────────────────────────────────────────────

    /** @test */
    public function curp_passes_with_exactly_18_uppercase_alphanumeric_characters(): void
    {
        $this->assertTrue((new Curp())->passes('curp', str_repeat('A', 18)));
        $this->assertTrue((new Curp())->passes('curp', 'ABCD123456HYNLRN09'));
    }

    /** @test */
    public function curp_fails_when_length_is_wrong(): void
    {
        $this->assertFalse((new Curp())->passes('curp', str_repeat('A', 17)));
        $this->assertFalse((new Curp())->passes('curp', str_repeat('A', 19)));
    }

    /** @test */
    public function curp_fails_when_lowercase(): void
    {
        $this->assertFalse((new Curp())->passes('curp', str_repeat('a', 18)));
    }

    /** @test */
    public function curp_fails_with_symbols(): void
    {
        $this->assertFalse((new Curp())->passes('curp', str_repeat('A', 17) . '-'));
    }

    /** @test */
    public function curp_fails_when_null(): void
    {
        $this->assertFalse((new Curp())->passes('curp', null));
    }

    // ── RFC ──────────────────────────────────────────────────────────────

    /** @test */
    public function rfc_passes_when_null_or_absent(): void
    {
        $this->assertTrue((new Rfc())->passes('rfc', null));
    }

    /** @test */
    public function rfc_passes_with_12_or_13_uppercase_alphanumeric_characters(): void
    {
        $this->assertTrue((new Rfc())->passes('rfc', str_repeat('A', 12)));
        $this->assertTrue((new Rfc())->passes('rfc', str_repeat('A', 13)));
    }

    /** @test */
    public function rfc_fails_when_length_is_wrong(): void
    {
        $this->assertFalse((new Rfc())->passes('rfc', str_repeat('A', 11)));
        $this->assertFalse((new Rfc())->passes('rfc', str_repeat('A', 14)));
    }

    /** @test */
    public function rfc_fails_when_lowercase_or_has_symbols(): void
    {
        $this->assertFalse((new Rfc())->passes('rfc', str_repeat('a', 12)));
        $this->assertFalse((new Rfc())->passes('rfc', str_repeat('A', 11) . '-'));
    }
}
