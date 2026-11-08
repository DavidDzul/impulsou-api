<?php

namespace Tests\Unit;

use App\Support\Scholarship\RefrendNetComponents;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (task 1.1): characterization snapshot of
 * RefrendNetComponents::of()->toArray() (the net_breakdown payload). Captured
 * on the code BEFORE ofMonth()/cents() were introduced; it must stay
 * byte-identical after the refactor (of() delegates to ofMonth()).
 */
class RefrendNetComponentsSnapshotTest extends TestCase
{
    private const BASE = [
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
    ];

    /** @return array<string,array{0:array<string,mixed>,1:string,2:string}> */
    public function snapshotCases(): array
    {
        return [
            'iu_plain' => [[], '1700.00',
                '{"beca":"1545.45","apoyo":"154.55","pago_iu":"0.00","temporary_increase":"0.00","telmex_coverage":"0.00","pending_and_advance":"0.00","total":"1700.00","nominal":{"beca":"2000.00","apoyo":"200.00","pago_iu":"0.00","temporary_increase":"0.00"}}'],
            'iu_increase' => [['snapshot_gross_amount' => '2700.00', 'snapshot_temporary_increase_amount' => '500.00'], '2200.00',
                '{"beca":"1629.63","apoyo":"162.96","pago_iu":"0.00","temporary_increase":"407.41","telmex_coverage":"0.00","pending_and_advance":"0.00","total":"2200.00","nominal":{"beca":"2000.00","apoyo":"200.00","pago_iu":"0.00","temporary_increase":"500.00"}}'],
            'iu_pending_advance' => [['amount_pending_from_previous' => '300.00', 'advance_payment_amount' => '1500.00'], '3500.00',
                '{"beca":"1545.45","apoyo":"154.55","pago_iu":"0.00","temporary_increase":"0.00","telmex_coverage":"0.00","pending_and_advance":"1800.00","total":"3500.00","nominal":{"beca":"2000.00","apoyo":"200.00","pago_iu":"0.00","temporary_increase":"0.00"}}'],
            'iu_floor' => [['snapshot_gross_amount' => '0.00', 'snapshot_monto_apoyo' => '0.00', 'base_amount' => '0.00'], '0.01',
                '{"beca":"0.01","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"0.00","telmex_coverage":"0.00","pending_and_advance":"0.00","total":"0.01","nominal":{"beca":"0.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"0.00"}}'],
            'iu_null_gross' => [['snapshot_gross_amount' => null, 'snapshot_monto_apoyo' => null], '2000.00',
                '{"beca":"2000.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"0.00","telmex_coverage":"0.00","pending_and_advance":"0.00","total":"2000.00","nominal":{"beca":"2000.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"0.00"}}'],
            'telmex_increase_only' => [['snapshot_scholarship_type' => 'TELMEX', 'snapshot_gross_amount' => '500.00', 'snapshot_temporary_increase_amount' => '500.00'], '500.00',
                '{"beca":"0.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"500.00","telmex_coverage":"0.00","pending_and_advance":"0.00","total":"500.00","nominal":{"beca":"0.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"500.00"}}'],
            'telmex_coverage' => [['snapshot_scholarship_type' => 'TELMEX', 'snapshot_gross_amount' => '500.00', 'snapshot_temporary_increase_amount' => '500.00', 'snapshot_telmex_coverage_id' => 7, 'snapshot_telmex_covered_amount' => '2000.00'], '2500.00',
                '{"beca":"0.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"500.00","telmex_coverage":"2000.00","pending_and_advance":"0.00","total":"2500.00","nominal":{"beca":"0.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"500.00"}}'],
            'telmex_coverage_clamped' => [['snapshot_scholarship_type' => 'TELMEX', 'snapshot_gross_amount' => '0.00', 'snapshot_telmex_coverage_id' => 7, 'snapshot_telmex_covered_amount' => '2000.00'], '1100.00',
                '{"beca":"0.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"0.00","telmex_coverage":"1100.00","pending_and_advance":"0.00","total":"1100.00","nominal":{"beca":"0.00","apoyo":"0.00","pago_iu":"0.00","temporary_increase":"0.00"}}'],
            'telmex_iu_pago_iu' => [['snapshot_scholarship_type' => 'TELMEX_IU', 'snapshot_gross_amount' => '1100.00', 'snapshot_temporary_increase_amount' => '100.00', 'snapshot_telmex_coverage_id' => 7, 'snapshot_telmex_covered_amount' => '1800.00'], '2800.00',
                '{"beca":"0.00","apoyo":"0.00","pago_iu":"909.09","temporary_increase":"90.91","telmex_coverage":"1800.00","pending_and_advance":"0.00","total":"2800.00","nominal":{"beca":"0.00","apoyo":"0.00","pago_iu":"1000.00","temporary_increase":"100.00"}}'],
            'telmex_iu_cycle_cut' => [['snapshot_scholarship_type' => 'TELMEX_IU', 'snapshot_gross_amount' => '1100.00', 'snapshot_telmex_coverage_id' => 7, 'snapshot_telmex_covered_amount' => '1800.00', 'resolution_type' => 'EGRESO_RETICULA'], '1100.00',
                '{"beca":"0.00","apoyo":"0.00","pago_iu":"1100.00","temporary_increase":"0.00","telmex_coverage":"0.00","pending_and_advance":"0.00","total":"1100.00","nominal":{"beca":"0.00","apoyo":"0.00","pago_iu":"1100.00","temporary_increase":"0.00"}}'],
        ];
    }

    /**
     * @test
     * @dataProvider snapshotCases
     */
    public function net_breakdown_output_is_byte_identical_to_the_captured_snapshot(array $overrides, string $total, string $expectedJson): void
    {
        $row = (object) array_merge(self::BASE, $overrides);

        $this->assertSame($expectedJson, json_encode(RefrendNetComponents::of($row, $total)->toArray()));
    }
}
