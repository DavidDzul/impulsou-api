<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Support\Scholarship\RefrendNetComponents;
use Tests\TestCase;

/**
 * sdd/descuento-beca-apoyo, design rev 2 (Option B): the read-side split of
 * the month's own payable into component nets. Pure PHP, no DB.
 */
class RefrendNetComponentsTest extends TestCase
{
    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'snapshot_scholarship_type'            => 'IU',
            'base_amount'                          => '2000.00',
            'snapshot_gross_amount'                => '2200.00',
            'snapshot_monto_apoyo'                 => '200.00',
            'snapshot_temporary_increase_amount'   => null,
            'snapshot_telmex_coverage_id'          => null,
            'snapshot_telmex_covered_amount'       => null,
            'resolution_type'                      => null,
            'final_amount'                         => '1700.00',
            'amount_pending_from_previous'         => '0.00',
            'advance_payment_amount'               => '0.00',
        ], $overrides);
    }

    private function net(object $row, string $total): array
    {
        return RefrendNetComponents::of($row, $total)->toArray();
    }

    private function sumCents(array $b): int
    {
        $sum = 0;
        foreach (['beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage', 'pending_and_advance'] as $key) {
            $sum += (int) round((float) $b[$key] * 100);
        }

        return $sum;
    }

    // ── 1.1 golden case ──────────────────────────────────────────────────────

    /** @test */
    public function golden_fixed_discount_splits_beca_and_apoyo_proportionally(): void
    {
        $b = $this->net($this->row(), '1700.00');

        $this->assertSame('1545.45', $b['beca']);
        $this->assertSame('154.55', $b['apoyo']);
        $this->assertSame('0.00', $b['pago_iu']);
        $this->assertSame('0.00', $b['temporary_increase']);
        $this->assertSame('0.00', $b['telmex_coverage']);
        $this->assertSame('0.00', $b['pending_and_advance']);
        $this->assertSame('1700.00', $b['total']);
        $this->assertSame(170000, $this->sumCents($b));
    }

    /** @test */
    public function to_array_exposes_the_nominal_components_as_two_decimal_strings(): void
    {
        $b = $this->net($this->row(), '1700.00');

        $this->assertSame(
            ['beca' => '2000.00', 'apoyo' => '200.00', 'pago_iu' => '0.00', 'temporary_increase' => '0.00'],
            $b['nominal']
        );
        $this->assertSame(
            ['beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage', 'pending_and_advance', 'total', 'nominal'],
            array_keys($b)
        );
    }

    /** @test */
    public function percentage_discount_splits_exactly(): void
    {
        $b = $this->net($this->row(['final_amount' => '1650.00']), '1650.00');

        $this->assertSame('1500.00', $b['beca']);
        $this->assertSame('150.00', $b['apoyo']);
    }

    /** @test */
    public function increase_is_a_proportional_component_and_apoyo_absorbs_the_remainder(): void
    {
        $row = $this->row([
            'base_amount'                        => '2000.00',
            'snapshot_gross_amount'              => '3000.00',
            'snapshot_monto_apoyo'               => '500.00',
            'snapshot_temporary_increase_amount' => '500.00',
            'final_amount'                       => '2900.00',
        ]);

        $b = $this->net($row, '2900.00');

        $this->assertSame('1933.33', $b['beca']);
        $this->assertSame('483.33', $b['temporary_increase']);
        $this->assertSame('483.34', $b['apoyo']);
        $this->assertSame(290000, $this->sumCents($b));
    }

    /** @test */
    public function an_enum_cast_type_is_handled_like_the_raw_string(): void
    {
        $b = $this->net($this->row(['snapshot_scholarship_type' => ScholarshipType::IU]), '1700.00');

        $this->assertSame('1545.45', $b['beca']);
    }

    // ── 1.2 weights per type ────────────────────────────────────────────────

    /** @test */
    public function telmex_iu_splits_between_pago_iu_and_increase_and_ignores_apoyo(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'          => 'TELMEX_IU',
            'base_amount'                        => '800.00',
            'snapshot_gross_amount'              => '1000.00',
            'snapshot_monto_apoyo'               => '300.00',
            'snapshot_temporary_increase_amount' => '200.00',
            'final_amount'                       => '500.00',
        ]);

        $b = $this->net($row, '500.00');

        $this->assertSame('400.00', $b['pago_iu']);
        $this->assertSame('100.00', $b['temporary_increase']);
        $this->assertSame('0.00', $b['beca']);
        $this->assertSame('0.00', $b['apoyo']);
        $this->assertSame('800.00', $b['nominal']['pago_iu']);
        $this->assertSame('0.00', $b['nominal']['apoyo']);
    }

    /** @test */
    public function telmex_pays_only_the_temporary_increase(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'          => 'TELMEX',
            'base_amount'                        => '0.00',
            'snapshot_gross_amount'              => '300.00',
            'snapshot_monto_apoyo'               => '100.00',
            'snapshot_temporary_increase_amount' => '300.00',
            'final_amount'                       => '270.00',
        ]);

        $b = $this->net($row, '270.00');

        $this->assertSame('270.00', $b['temporary_increase']);
        $this->assertSame('0.00', $b['beca']);
        $this->assertSame('0.00', $b['apoyo']);
        $this->assertSame('0.00', $b['pago_iu']);
    }

    /** @test */
    public function a_null_gross_falls_back_to_the_base_amount(): void
    {
        $row = $this->row(['snapshot_gross_amount' => null, 'base_amount' => '2000.00', 'snapshot_monto_apoyo' => '200.00']);

        $b = $this->net($row, '1800.00');

        $this->assertSame('1800.00', $b['nominal']['beca']);
        $this->assertSame('200.00', $b['nominal']['apoyo']);
        $this->assertSame('1620.00', $b['beca']);
        $this->assertSame('180.00', $b['apoyo']);
    }

    /** @test */
    public function zero_weight_sends_the_payable_to_the_primary_component(): void
    {
        $iu = $this->net($this->row(['snapshot_gross_amount' => '0.00', 'base_amount' => '0.00', 'snapshot_monto_apoyo' => '0.00']), '0.01');
        $this->assertSame('0.01', $iu['beca']);

        $tmxIu = $this->net($this->row(['snapshot_scholarship_type' => 'TELMEX_IU', 'snapshot_gross_amount' => '0.00', 'base_amount' => '0.00']), '12.00');
        $this->assertSame('12.00', $tmxIu['pago_iu']);

        $tmx = $this->net($this->row(['snapshot_scholarship_type' => 'TELMEX', 'snapshot_gross_amount' => '0.00', 'base_amount' => '0.00', 'snapshot_monto_apoyo' => '0.00']), '45.50');
        $this->assertSame('45.50', $tmx['temporary_increase']);
    }

    // ── 1.3 pending_and_advance carve-out ───────────────────────────────────

    /** @test */
    public function pending_and_advance_are_carved_out_and_never_split(): void
    {
        $row = $this->row([
            'amount_pending_from_previous' => '100.00',
            'advance_payment_amount'       => '50.00',
        ]);

        $b = $this->net($row, '1850.00');

        $this->assertSame('150.00', $b['pending_and_advance']);
        $this->assertSame('1545.45', $b['beca']);
        $this->assertSame('154.55', $b['apoyo']);
        $this->assertSame('1850.00', $b['total']);
        $this->assertSame(185000, $this->sumCents($b));
    }

    /** @test */
    public function null_or_missing_pending_and_advance_count_as_zero(): void
    {
        $row = $this->row(['amount_pending_from_previous' => null]);
        unset($row->advance_payment_amount);

        $b = $this->net($row, '1700.00');

        $this->assertSame('0.00', $b['pending_and_advance']);
        $this->assertSame('1545.45', $b['beca']);
    }

    /** @test */
    public function a_zero_month_payable_gives_all_zero_nets(): void
    {
        $b = $this->net($this->row(['final_amount' => '0.00']), '0.00');

        foreach (['beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage', 'pending_and_advance', 'total'] as $key) {
            $this->assertSame('0.00', $b[$key], $key);
        }
    }

    /** @test */
    public function only_carryover_money_leaves_every_net_at_zero(): void
    {
        $row = $this->row(['final_amount' => '0.00', 'amount_pending_from_previous' => '300.00']);

        $b = $this->net($row, '300.00');

        $this->assertSame('300.00', $b['pending_and_advance']);
        $this->assertSame('0.00', $b['beca']);
        $this->assertSame('0.00', $b['apoyo']);
    }

    // ── 1.4 telmex coverage ─────────────────────────────────────────────────

    /** @test */
    public function covered_money_is_excluded_from_the_split_and_reported_apart(): void
    {
        $row = $this->row([
            'snapshot_scholarship_type'          => 'TELMEX_IU',
            'base_amount'                        => '800.00',
            'snapshot_gross_amount'              => '1000.00',
            'snapshot_temporary_increase_amount' => '200.00',
            'snapshot_telmex_coverage_id'        => 7,
            'snapshot_telmex_covered_amount'     => '500.00',
            'final_amount'                       => '1500.00',
        ]);

        $b = $this->net($row, '1500.00');

        $this->assertSame('500.00', $b['telmex_coverage']);
        $this->assertSame('800.00', $b['pago_iu']);
        $this->assertSame('200.00', $b['temporary_increase']);
        $this->assertSame(150000, $this->sumCents($b));
    }

    /** @test */
    public function covered_money_is_ignored_without_a_coverage_id(): void
    {
        $row = $this->row(['snapshot_telmex_covered_amount' => '500.00']);

        $this->assertSame(0, RefrendNetComponents::coveredIncludedCents($row));
    }

    /** @test */
    public function covered_money_is_not_included_on_terminal_cycle_cuts_nor_egreso_reticula(): void
    {
        foreach (['EGRESADO', 'BAJA_DEFINITIVA', 'EGRESO_RETICULA'] as $resolution) {
            $row = $this->row([
                'snapshot_telmex_coverage_id'    => 7,
                'snapshot_telmex_covered_amount' => '500.00',
                'resolution_type'                => $resolution,
                'final_amount'                   => '0.00',
            ]);

            $this->assertSame(0, RefrendNetComponents::coveredIncludedCents($row), $resolution);
            $this->assertSame('0.00', $this->net($row, '0.00')['telmex_coverage'], $resolution);
        }
    }

    /** @test */
    public function covered_money_is_clamped_to_the_month_payable(): void
    {
        $row = $this->row([
            'snapshot_telmex_coverage_id'    => 7,
            'snapshot_telmex_covered_amount' => '500.00',
            'final_amount'                   => '300.00',
        ]);

        $b = $this->net($row, '300.00');

        $this->assertSame('300.00', $b['telmex_coverage']);
        $this->assertSame('0.00', $b['beca']);
        $this->assertSame(30000, $this->sumCents($b));
    }

    /** @test */
    public function covered_cents_are_exposed_unclamped_by_the_helper(): void
    {
        $row = $this->row(['snapshot_telmex_coverage_id' => 7, 'snapshot_telmex_covered_amount' => '500.10']);

        $this->assertSame(50010, RefrendNetComponents::coveredIncludedCents($row));
    }

    // ── 1.5 rounding order, floor ───────────────────────────────────────────

    /** @test */
    public function apoyo_zero_gives_a_zero_apoyo_net_and_beca_takes_everything(): void
    {
        $row = $this->row(['snapshot_gross_amount' => '2000.00', 'snapshot_monto_apoyo' => '0.00', 'final_amount' => '1500.00']);

        $b = $this->net($row, '1500.00');

        $this->assertSame('1500.00', $b['beca']);
        $this->assertSame('0.00', $b['apoyo']);
    }

    /** @test */
    public function without_a_temporary_increase_the_increase_net_is_zero(): void
    {
        $this->assertSame('0.00', $this->net($this->row(), '1700.00')['temporary_increase']);
    }

    /** @test */
    public function the_one_cent_floor_lands_in_the_month_split_not_in_carryover(): void
    {
        $row = $this->row(['final_amount' => '0.00']);

        $b = $this->net($row, '0.01');

        $this->assertSame('0.00', $b['pending_and_advance']);
        $this->assertSame('0.01', $b['beca']);
        $this->assertSame('0.00', $b['apoyo']);
        $this->assertSame(1, $this->sumCents($b));
    }

    /** @test */
    public function the_floor_with_carryover_keeps_pending_and_advance_equal_to_the_ledger(): void
    {
        $row = $this->row(['final_amount' => '0.00', 'amount_pending_from_previous' => '0.00', 'advance_payment_amount' => '0.00']);

        $b = $this->net($row, '0.01');

        $this->assertSame('0.00', $b['pending_and_advance']);
    }

    /** @test */
    public function the_last_positive_component_takes_the_remainder_so_the_sum_is_exact(): void
    {
        // 3 equal weights over 100.00: 33.33 + 33.33 + 33.34
        $row = $this->row([
            'snapshot_gross_amount'              => '300.00',
            'snapshot_monto_apoyo'               => '100.00',
            'snapshot_temporary_increase_amount' => '100.00',
            'final_amount'                       => '100.00',
        ]);

        $b = $this->net($row, '100.00');

        $this->assertSame('33.33', $b['beca']);
        $this->assertSame('33.33', $b['temporary_increase']);
        $this->assertSame('33.34', $b['apoyo']);
    }

    /** @test */
    public function the_increase_is_last_when_apoyo_is_zero(): void
    {
        $row = $this->row([
            'snapshot_gross_amount'              => '3000.00',
            'snapshot_monto_apoyo'               => '0.00',
            'snapshot_temporary_increase_amount' => '1000.00',
            'final_amount'                       => '100.00',
        ]);

        $b = $this->net($row, '100.00');

        $this->assertSame('66.67', $b['beca']);
        $this->assertSame('33.33', $b['temporary_increase']);
        $this->assertSame('0.00', $b['apoyo']);
    }
}
