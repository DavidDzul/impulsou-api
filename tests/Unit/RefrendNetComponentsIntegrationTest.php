<?php

namespace Tests\Unit;

use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipCalculationService;
use App\Support\Scholarship\RefrendNetComponents;
use App\Support\Scholarship\RefrendPayableTotal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/descuento-beca-apoyo: the VO fed with what ScholarshipCalculationService
 * really produces (enum-cast Eloquent model, real final_amount), plus the
 * regression lock that calculateFinalAmount is untouched (S1.10).
 */
class RefrendNetComponentsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function refrend(array $attributes): ScholarshipRefrend
    {
        return (new ScholarshipRefrend())->forceFill(array_merge([
            'snapshot_scholarship_type'          => 'IU',
            'base_amount'                        => 2000.00,
            'snapshot_gross_amount'              => 2200.00,
            'snapshot_monto_apoyo'               => 200.00,
            'snapshot_temporary_increase_amount' => null,
            'snapshot_discount_percentage'       => 0,
            'snapshot_telmex_coverage_id'        => null,
            'snapshot_telmex_covered_amount'     => null,
            'amount_pending_from_previous'       => 0,
            'advance_payment_amount'             => 0,
        ], $attributes));
    }

    private function assertInvariant(ScholarshipRefrend $refrend): array
    {
        $final = app(ScholarshipCalculationService::class)->calculateFinalAmount($refrend)['final_amount'];
        $refrend->final_amount = $final;

        $total = RefrendPayableTotal::of($refrend);
        $b     = RefrendNetComponents::of($refrend, $total)->toArray();

        $sum = 0;
        foreach (['beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage', 'pending_and_advance'] as $key) {
            $sum += (int) round((float) $b[$key] * 100);
        }
        $this->assertSame((int) round((float) $total * 100), $sum);
        $this->assertSame($total, $b['total']);

        return $b;
    }

    /** @test */
    public function an_iu_refrend_with_an_academic_discount_reconciles(): void
    {
        $b = $this->assertInvariant($this->refrend(['snapshot_discount_percentage' => 25]));

        // 2200 * 0.75 = 1650.00 -> 1500.00 / 150.00
        $this->assertSame('1500.00', $b['beca']);
        $this->assertSame('150.00', $b['apoyo']);
    }

    /** @test */
    public function an_iu_refrend_with_increase_pending_and_advance_reconciles(): void
    {
        $b = $this->assertInvariant($this->refrend([
            'base_amount'                        => 2000.00,
            'snapshot_gross_amount'              => 2700.00,
            'snapshot_temporary_increase_amount' => 500.00,
            'snapshot_discount_percentage'       => 10,
            'amount_pending_from_previous'       => 123.45,
            'advance_payment_amount'             => 0.55,
        ]));

        $this->assertSame('124.00', $b['pending_and_advance']);
    }

    /** @test */
    public function a_covered_telmex_iu_refrend_reconciles(): void
    {
        $b = $this->assertInvariant($this->refrend([
            'snapshot_scholarship_type'          => 'TELMEX_IU',
            'base_amount'                        => 800.00,
            'snapshot_gross_amount'              => 1000.00,
            'snapshot_temporary_increase_amount' => 200.00,
            'snapshot_discount_percentage'       => 12.5,
            'snapshot_telmex_coverage_id'        => 3,
            'snapshot_telmex_covered_amount'     => 700.00,
        ]));

        $this->assertSame('700.00', $b['telmex_coverage']);
        $this->assertSame('0.00', $b['beca']);
    }

    /** @test */
    public function a_fully_discounted_refrend_yields_zero_nets(): void
    {
        $refrend = $this->refrend(['snapshot_discount_percentage' => 100]);

        $b = $this->assertInvariant($refrend);

        $this->assertSame('0.00', $b['beca']);
        $this->assertSame('0.00', $b['apoyo']);
        $this->assertSame('0.00', $b['total']);
    }

    /** @test */
    public function calculate_final_amount_is_unchanged_by_the_net_breakdown(): void
    {
        $service = app(ScholarshipCalculationService::class);
        $refrend = $this->refrend(['snapshot_discount_percentage' => 25]);

        $before = $service->calculateFinalAmount($refrend);
        RefrendNetComponents::of($refrend, '1650.00')->toArray();
        $after = $service->calculateFinalAmount($refrend);

        $this->assertSame($before, $after);
        $this->assertSame(1650.0, $after['final_amount']);
    }
}
