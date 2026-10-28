<?php

namespace Tests\Unit;

use App\Support\Scholarship\RefrendPayableTotal;
use Tests\TestCase;

/**
 * sdd/telmex-pendiente-lote, design D4: the unfloored payable-total formula
 * (final_amount + pending + advance) lives once here.
 */
class RefrendPayableTotalTest extends TestCase
{
    /** @test */
    public function it_sums_final_pending_and_advance_as_a_two_decimal_string(): void
    {
        $row = (object) ['final_amount' => 0, 'amount_pending_from_previous' => 100, 'advance_payment_amount' => 50];

        $this->assertSame('150.00', RefrendPayableTotal::of($row));
    }

    /** @test */
    public function null_pending_and_advance_count_as_zero(): void
    {
        $row = (object) ['final_amount' => '1000.5', 'amount_pending_from_previous' => null, 'advance_payment_amount' => null];

        $this->assertSame('1000.50', RefrendPayableTotal::of($row));
    }

    /** @test */
    public function missing_pending_and_advance_properties_count_as_zero(): void
    {
        $this->assertSame('0.00', RefrendPayableTotal::of((object) ['final_amount' => 0]));
    }

    /** @test */
    public function decimal_strings_are_summed_numerically(): void
    {
        $row = (object) ['final_amount' => '10.10', 'amount_pending_from_previous' => '0.20', 'advance_payment_amount' => '0.05'];

        $this->assertSame('10.35', RefrendPayableTotal::of($row));
    }

    /** @test */
    public function negative_totals_are_not_floored(): void
    {
        $row = (object) ['final_amount' => -50, 'amount_pending_from_previous' => 20, 'advance_payment_amount' => 0];

        $this->assertSame('-30.00', RefrendPayableTotal::of($row));
    }

    /** @test */
    public function offsetting_amounts_yield_zero_not_the_payable_floor(): void
    {
        $row = (object) ['final_amount' => -50, 'amount_pending_from_previous' => 50, 'advance_payment_amount' => 0];

        $this->assertSame('0.00', RefrendPayableTotal::of($row));
    }
}
