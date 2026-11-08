<?php

namespace Tests\Unit;

use App\Support\Scholarship\RefrendConceptSplit;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (task 1.3): proportional() + VO basics.
 * Beca is rounded half-up, Apoyo is the remainder; integer cents only.
 */
class RefrendConceptSplitProportionalTest extends TestCase
{
    private function w(int $beca, int $apoyo): RefrendConceptSplit
    {
        return RefrendConceptSplit::of($beca, $apoyo);
    }

    /** @test */
    public function sc2_rounds_beca_half_up_and_apoyo_takes_the_remainder(): void
    {
        $s = RefrendConceptSplit::proportional(50000, $this->w(200000, 20000));

        $this->assertSame(45455, $s->becaCents());
        $this->assertSame(4545, $s->apoyoCents());
    }

    /** @test */
    public function sc3_one_hundred_over_1000_to_500(): void
    {
        $s = RefrendConceptSplit::proportional(10000, $this->w(100000, 50000));

        $this->assertSame([6667, 3333], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function sc4_a_single_cent_goes_to_beca(): void
    {
        $s = RefrendConceptSplit::proportional(1, $this->w(200000, 20000));

        $this->assertSame([1, 0], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function sc6_zero_weights_send_everything_to_beca(): void
    {
        $s = RefrendConceptSplit::proportional(50000, $this->w(0, 0));

        $this->assertSame([50000, 0], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function zero_weights_use_the_fallback_when_it_has_weight(): void
    {
        $s = RefrendConceptSplit::proportional(10000, $this->w(0, 0), $this->w(900, 100));

        $this->assertSame([9000, 1000], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function zero_weights_and_zero_fallback_send_everything_to_beca(): void
    {
        $s = RefrendConceptSplit::proportional(10000, $this->w(0, 0), $this->w(0, 0));

        $this->assertSame([10000, 0], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function the_fallback_is_ignored_when_the_weights_have_weight(): void
    {
        $s = RefrendConceptSplit::proportional(10000, $this->w(1, 1), $this->w(900, 100));

        $this->assertSame([5000, 5000], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function apoyo_only_weights_send_everything_to_apoyo(): void
    {
        $s = RefrendConceptSplit::proportional(777, $this->w(0, 50));

        $this->assertSame([0, 777], [$s->becaCents(), $s->apoyoCents()]);
    }

    /** @test */
    public function non_positive_cents_yield_an_empty_split(): void
    {
        $this->assertSame([0, 0], [
            RefrendConceptSplit::proportional(0, $this->w(1, 1))->becaCents(),
            RefrendConceptSplit::proportional(-5, $this->w(1, 1))->apoyoCents(),
        ]);
    }

    /** @test */
    public function to_array_and_total_expose_two_decimal_strings_and_cents(): void
    {
        $s = $this->w(45455, 4545);

        $this->assertSame(['beca' => '454.55', 'apoyo' => '45.45'], $s->toArray());
        $this->assertSame(50000, $s->totalCents());
    }

    /** @test */
    public function columns_cover_every_refrend_field_the_split_reads(): void
    {
        foreach (['id', 'final_amount', 'snapshot_scholarship_type', 'snapshot_gross_amount', 'snapshot_monto_apoyo',
            'snapshot_temporary_increase_amount', 'snapshot_telmex_coverage_id', 'snapshot_telmex_covered_amount',
            'resolution_type', 'base_amount'] as $column) {
            $this->assertContains($column, RefrendConceptSplit::COLUMNS);
        }
    }
}
