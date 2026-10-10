<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-iu, design D4 / decisions-2 dec2: the covered Telmex
 * part is added to final_amount OUTSIDE the IU-only $dueAmount computation
 * for every resolution type EXCEPT BAJA_DEFINITIVA. The withholding ledger
 * (RETENIDA) is computed from the IU-only $dueAmount and must stay
 * untouched by the covered part.
 */
class RecordPaymentSituationActionTelmexCoverageTest extends TestCase
{
    use RefreshDatabase;

    private RecordPaymentSituationAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(RecordPaymentSituationAction::class);
        $this->actingAs(User::factory()->create(['user_type' => 'ADMIN', 'active' => true]));
    }

    private function makeCoveredRefrend(float $baseAmount, float $coveredAmount, array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        $coverage = TelmexCoverage::create([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => '2026-01-01',
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                        => $user->id,
            'period_year'                    => 2026,
            'period_month'                   => 1,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::DRAFT->value,
            'workflow_status'                => 'DRAFT',
            'base_amount'                    => $baseAmount,
            'snapshot_gross_amount'          => $baseAmount,
            'snapshot_telmex_covered_amount' => $coveredAmount,
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'discount_percentage'            => 0,
            'discount_amount'                => 0,
            'final_amount'                   => $baseAmount,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_generation'            => null,
            'snapshot_generation_id'         => null,
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => ScholarshipType::TELMEX->value,
        ], $overrides));
    }

    /** @test */
    public function sin_pago_still_pays_the_covered_part(): void
    {
        $refrend = $this->makeCoveredRefrend(0.0, 1950.0);

        $result = $this->action->execute($refrend, ['resolution_type' => 'SIN_PAGO'], $refrend->user_id);

        $this->assertSame('1950.00', $result->final_amount);
    }

    /** @test */
    public function retenida_keeps_the_covered_part_and_ledger_stays_iu_only(): void
    {
        // IU-sourced gross is 1000 (a covered TELMEX_IU scenario), retained
        // 30% -> withheld 300 (IU-only), final = 700 iu + 1950 covered.
        $refrend = $this->makeCoveredRefrend(1000.0, 1950.0);

        $result = $this->action->execute($refrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $refrend->user_id);

        $this->assertSame('2650.00', $result->final_amount); // 700 + 1950
        $this->assertSame('300.00', $result->discount_amount);

        $ledger = ScholarshipWithholding::where('origin_refrend_id', $refrend->id)->first();
        $this->assertNotNull($ledger);
        $this->assertSame('300.00', (string) $ledger->withheld_amount); // IU-only, not 2250
    }

    /** @test */
    public function suspendida_keeps_the_covered_part(): void
    {
        $refrend = $this->makeCoveredRefrend(1000.0, 1950.0);

        $result = $this->action->execute($refrend, [
            'resolution_type'        => 'SUSPENDIDA',
            'suspension_percentage'  => 50,
        ], $refrend->user_id);

        $this->assertSame('2450.00', $result->final_amount); // 500 iu + 1950 covered
    }

    /** @test */
    public function baja_definitiva_zeroes_the_covered_part(): void
    {
        $refrend = $this->makeCoveredRefrend(1000.0, 1950.0);

        $result = $this->action->execute($refrend, ['resolution_type' => 'BAJA_DEFINITIVA'], $refrend->user_id);

        $this->assertSame('0.00', $result->final_amount);
    }

    /** @test */
    public function beca_mes_keeps_the_covered_part(): void
    {
        $refrend = $this->makeCoveredRefrend(1000.0, 1950.0);

        $result = $this->action->execute($refrend, ['resolution_type' => 'BECA_MES'], $refrend->user_id);

        $this->assertSame('2950.00', $result->final_amount); // 1000 + 1950
    }

    /** @test */
    public function uncovered_refrend_is_byte_identical_to_the_pre_change_behavior(): void
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        $refrend = ScholarshipRefrend::create([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 1,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);

        $result = $this->action->execute($refrend, ['resolution_type' => 'SIN_PAGO'], $refrend->user_id);

        $this->assertSame('0.00', $result->final_amount);
    }
}
