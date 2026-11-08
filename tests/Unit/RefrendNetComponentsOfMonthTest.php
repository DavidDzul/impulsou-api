<?php

namespace Tests\Unit;

use App\Support\Scholarship\RefrendNetComponents;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (task 1.2): ofMonth() / cents() additions.
 */
class RefrendNetComponentsOfMonthTest extends TestCase
{
    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'snapshot_scholarship_type'          => 'IU',
            'base_amount'                        => '2000.00',
            'snapshot_gross_amount'              => '2200.00',
            'snapshot_monto_apoyo'               => '200.00',
            'snapshot_temporary_increase_amount' => null,
            'snapshot_telmex_coverage_id'        => null,
            'snapshot_telmex_covered_amount'     => null,
            'resolution_type'                    => null,
            'final_amount'                       => '1700.00',
            'amount_pending_from_previous'       => '0.00',
            'advance_payment_amount'             => '0.00',
        ], $overrides);
    }

    /** @test */
    public function of_month_equals_of_when_there_is_no_carryover(): void
    {
        $row = $this->row(['snapshot_gross_amount' => '2700.00', 'snapshot_temporary_increase_amount' => '500.00']);

        $this->assertSame(
            RefrendNetComponents::of($row, '2200.00')->toArray(),
            RefrendNetComponents::ofMonth($row, 220000)->toArray()
        );
    }

    /** @test */
    public function of_month_ignores_the_row_carryover_columns(): void
    {
        $row = $this->row(['amount_pending_from_previous' => '300.00', 'advance_payment_amount' => '1500.00']);

        $b = RefrendNetComponents::ofMonth($row, 170000)->toArray();

        $this->assertSame('0.00', $b['pending_and_advance']);
        $this->assertSame('1700.00', $b['total']);
        $this->assertSame('1545.45', $b['beca']);
        $this->assertSame('154.55', $b['apoyo']);
    }

    /** @test */
    public function of_month_scales_the_split_with_the_given_month_cents(): void
    {
        $b = RefrendNetComponents::ofMonth($this->row(), 100000)->toArray();

        $this->assertSame('909.09', $b['beca']);
        $this->assertSame('90.91', $b['apoyo']);
        $this->assertSame('1000.00', $b['total']);
    }

    /** @test */
    public function of_month_clamps_telmex_coverage_to_the_month(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'     => 'TELMEX',
            'snapshot_gross_amount'         => '0.00',
            'snapshot_telmex_coverage_id'   => 7,
            'snapshot_telmex_covered_amount' => '2000.00',
        ]);

        $this->assertSame('1100.00', RefrendNetComponents::ofMonth($row, 110000)->toArray()['telmex_coverage']);
    }

    /** @test */
    public function cents_returns_integer_components_matching_to_array(): void
    {
        $n = RefrendNetComponents::of($this->row(['amount_pending_from_previous' => '300.00']), '2000.00');

        $this->assertSame([
            'beca'                => 154545,
            'apoyo'               => 15455,
            'pago_iu'             => 0,
            'temporary_increase'  => 0,
            'telmex_coverage'     => 0,
            'pending_and_advance' => 30000,
            'total'               => 200000,
            'nominal'             => ['beca' => 200000, 'apoyo' => 20000, 'pago_iu' => 0, 'temporary_increase' => 0],
        ], $n->cents());
    }
}
