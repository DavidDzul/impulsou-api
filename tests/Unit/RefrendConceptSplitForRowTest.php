<?php

namespace Tests\Unit;

use App\Support\Scholarship\RefrendConceptSplit;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (task 1.5): forRow() (month + carryover +
 * floor), scaleTo() (ledger split rescaled to the column) and
 * fallbackWeights() (D3, D4, D5).
 */
class RefrendConceptSplitForRowTest extends TestCase
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

    private function pair(RefrendConceptSplit $s): array
    {
        return [$s->becaCents(), $s->apoyoCents()];
    }

    // ── forRow ───────────────────────────────────────────────────────────────

    /** @test */
    public function a_plain_row_is_its_month_split(): void
    {
        $s = RefrendConceptSplit::forRow($this->row(), 200000, 200000, RefrendConceptSplit::zero());

        $this->assertSame([180000, 20000], $this->pair($s));
    }

    /** @test */
    public function sc15_the_floor_cent_goes_to_beca_only(): void
    {
        $row = $this->row(['snapshot_gross_amount' => '0.00', 'snapshot_monto_apoyo' => '0.00', 'final_amount' => '0.00']);

        $s = RefrendConceptSplit::forRow($row, 0, 1, RefrendConceptSplit::zero());

        $this->assertSame([1, 0], $this->pair($s));
    }

    /** @test */
    public function the_floor_cent_goes_to_beca_even_when_the_weights_favour_apoyo(): void
    {
        $row = $this->row(['snapshot_gross_amount' => '200.00', 'final_amount' => '0.00']);

        $s = RefrendConceptSplit::forRow($row, 0, 1, RefrendConceptSplit::zero());

        $this->assertSame([1, 0], $this->pair($s));
    }

    /** @test */
    public function sc16_an_apoyo_only_row_gets_no_floor_cent(): void
    {
        $row = $this->row(['snapshot_gross_amount' => '200.00', 'final_amount' => '200.00']);

        $s = RefrendConceptSplit::forRow($row, 20000, 20000, RefrendConceptSplit::zero());

        $this->assertSame([0, 20000], $this->pair($s));
    }

    /** @test */
    public function sc17_a_manual_final_amount_override_is_split_by_the_row_ratio(): void
    {
        $row = $this->row(['snapshot_gross_amount' => '1000.00', 'snapshot_monto_apoyo' => '100.00', 'final_amount' => '1000.00']);

        $s = RefrendConceptSplit::forRow($row, 100000, 100000, RefrendConceptSplit::zero());

        $this->assertSame([90000, 10000], $this->pair($s));
    }

    /** @test */
    public function the_carryover_is_added_on_top_of_the_month_split(): void
    {
        $row = $this->row(['amount_pending_from_previous' => '300.00']);

        $s = RefrendConceptSplit::forRow($row, 230000, 230000, RefrendConceptSplit::of(25000, 5000));

        $this->assertSame([205000, 25000], $this->pair($s));
    }

    /** @test */
    public function a_carryover_larger_than_the_total_is_clamped_so_the_sum_still_matches(): void
    {
        $s = RefrendConceptSplit::forRow($this->row(), 10000, 10000, RefrendConceptSplit::of(9000, 6000));

        $this->assertSame(10000, $s->totalCents());
        $this->assertSame([4000, 6000], $this->pair($s));
    }

    // ── scaleTo ──────────────────────────────────────────────────────────────

    /** @test */
    public function scale_to_the_same_target_is_the_identity(): void
    {
        $s = RefrendConceptSplit::of(235000, 65000)->scaleTo(300000);

        $this->assertSame([235000, 65000], $this->pair($s));
    }

    /** @test */
    public function scale_to_a_smaller_target_rounds_apoyo_and_leaves_the_residual_in_beca(): void
    {
        $s = RefrendConceptSplit::of(235000, 65000)->scaleTo(200000);

        $this->assertSame([156667, 43333], $this->pair($s));
    }

    /** @test */
    public function scale_to_a_larger_target_keeps_the_ratio_and_never_exceeds_the_target(): void
    {
        $s = RefrendConceptSplit::of(0, 100)->scaleTo(250);

        $this->assertSame([0, 250], $this->pair($s));
    }

    /** @test */
    public function an_empty_ledger_split_uses_the_fallback_weights(): void
    {
        $s = RefrendConceptSplit::zero()->scaleTo(10000, RefrendConceptSplit::of(900, 100));

        $this->assertSame([9000, 1000], $this->pair($s));
    }

    /** @test */
    public function an_empty_ledger_split_without_fallback_is_all_beca(): void
    {
        $this->assertSame([10000, 0], $this->pair(RefrendConceptSplit::zero()->scaleTo(10000)));
    }

    /** @test */
    public function scale_to_a_zero_target_is_empty(): void
    {
        $this->assertSame([0, 0], $this->pair(RefrendConceptSplit::of(5, 5)->scaleTo(0)));
    }

    // ── hasWeights ───────────────────────────────────────────────────────────

    /** @test */
    public function has_weights_is_true_for_nominal_beca_apoyo_or_pago_iu(): void
    {
        $this->assertTrue(RefrendConceptSplit::hasWeights($this->row()));
        $this->assertTrue(RefrendConceptSplit::hasWeights($this->row([
            'snapshot_scholarship_type' => 'TELMEX_IU', 'snapshot_gross_amount' => '100.00', 'snapshot_monto_apoyo' => '0.00',
        ])));
    }

    /** @test */
    public function has_weights_is_false_when_every_nominal_is_zero(): void
    {
        $this->assertFalse(RefrendConceptSplit::hasWeights($this->row([
            'snapshot_gross_amount' => '0.00', 'snapshot_monto_apoyo' => '0.00', 'base_amount' => '0.00',
        ])));
    }

    // ── fallbackWeights ──────────────────────────────────────────────────────

    /** @test */
    public function fallback_weights_use_the_month_split_of_the_final_amount(): void
    {
        $this->assertSame([180000, 20000], $this->pair(RefrendConceptSplit::fallbackWeights($this->row())));
    }

    /** @test */
    public function fallback_weights_use_the_concept_weights_when_the_final_amount_is_zero(): void
    {
        $row = $this->row(['final_amount' => '0.00']);

        $this->assertSame([180000, 20000], $this->pair(RefrendConceptSplit::fallbackWeights($row)));
    }

    /** @test */
    public function fallback_weights_are_empty_when_there_is_nothing_to_weigh(): void
    {
        $row = $this->row(['final_amount' => '0.00', 'snapshot_gross_amount' => '0.00', 'snapshot_monto_apoyo' => '0.00']);

        $this->assertSame(0, RefrendConceptSplit::fallbackWeights($row)->totalCents());
    }
}
