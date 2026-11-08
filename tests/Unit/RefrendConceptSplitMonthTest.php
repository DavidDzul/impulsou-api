<?php

namespace Tests\Unit;

use App\Support\Scholarship\RefrendConceptSplit;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (task 1.4): month() splits one month's payable
 * into Beca / Apoyo using RefrendNetComponents::ofMonth + concept weights.
 */
class RefrendConceptSplitMonthTest extends TestCase
{
    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'snapshot_scholarship_type'          => 'IU',
            'base_amount'                        => '2000.00',
            'snapshot_gross_amount'              => '2000.00',
            'snapshot_monto_apoyo'               => '200.00',
            'snapshot_temporary_increase_amount' => null,
            'snapshot_telmex_coverage_id'        => null,
            'snapshot_telmex_covered_amount'     => null,
            'resolution_type'                    => null,
            'final_amount'                       => '2000.00',
            'amount_pending_from_previous'       => '0.00',
            'advance_payment_amount'             => '0.00',
        ], $overrides);
    }

    /** @test */
    public function sc1_plain_iu_month_is_net_beca_and_net_apoyo(): void
    {
        $s = RefrendConceptSplit::month($this->row(), 200000);

        $this->assertSame([180000, 20000], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function pago_iu_goes_entirely_to_beca(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'      => 'TELMEX_IU',
            'snapshot_gross_amount'          => '100.00',
            'snapshot_telmex_coverage_id'    => 7,
            'snapshot_telmex_covered_amount' => '2000.00',
        ]);

        // 100.00 pago_iu + 2000.00 coverage (1800 beca : 200 apoyo)
        $s = RefrendConceptSplit::month($row, 210000);

        $this->assertSame([190000, 20000], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function sc5_telmex_increase_only_splits_by_the_telmex_nominals(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'          => 'TELMEX',
            'snapshot_gross_amount'              => '500.00',
            'snapshot_temporary_increase_amount' => '500.00',
            'snapshot_telmex_covered_amount'     => '2000.00',
        ]);

        $s = RefrendConceptSplit::month($row, 50000);

        $this->assertSame([45000, 5000], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function unclamped_telmex_coverage_gives_apoyo_exactly_the_monto_apoyo(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'      => 'TELMEX',
            'snapshot_gross_amount'          => '0.00',
            'snapshot_telmex_coverage_id'    => 7,
            'snapshot_telmex_covered_amount' => '2000.00',
        ]);

        $s = RefrendConceptSplit::month($row, 200000);

        $this->assertSame([180000, 20000], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function sc7_clamped_coverage_is_scaled_by_its_own_nominals(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'      => 'TELMEX',
            'snapshot_gross_amount'          => '0.00',
            'snapshot_telmex_coverage_id'    => 7,
            'snapshot_telmex_covered_amount' => '2200.00', // 2000 beca : 200 apoyo
        ]);

        $s = RefrendConceptSplit::month($row, 110000);

        $this->assertSame([100000, 10000], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function the_telmex_weights_ignore_the_coverage_fk(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'          => 'TELMEX',
            'snapshot_gross_amount'              => '500.00',
            'snapshot_temporary_increase_amount' => '500.00',
            'snapshot_telmex_coverage_id'        => null,
            'snapshot_telmex_covered_amount'     => '2000.00',
        ]);

        $this->assertSame(5000, RefrendConceptSplit::month($row, 50000)->apoyoCents());
    }

    /** @test */
    public function an_iu_increase_is_split_by_the_nominal_beca_to_apoyo_ratio(): void
    {
        $row = $this->row([
            'snapshot_gross_amount'              => '2700.00',
            'snapshot_temporary_increase_amount' => '500.00',
        ]);

        $s = RefrendConceptSplit::month($row, 270000);

        // beca 2000 + apoyo 200 + increase 500 (454.55 / 45.45 at 2000:200)
        $this->assertSame([245455, 24545], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function a_row_with_no_nominal_weights_is_all_beca(): void
    {
        $row = $this->row(['snapshot_gross_amount' => '0.00', 'snapshot_monto_apoyo' => '0.00', 'base_amount' => '0.00']);

        $s = RefrendConceptSplit::month($row, 5000);

        $this->assertSame([5000, 0], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function the_month_split_always_adds_up_to_the_month_cents(): void
    {
        $row = $this->row(['snapshot_gross_amount' => '2700.00', 'snapshot_temporary_increase_amount' => '500.00']);

        foreach ([1, 7, 99999, 123457, 270000, 999999] as $cents) {
            $this->assertSame($cents, RefrendConceptSplit::month($row, $cents)->totalCents());
        }
    }
}
