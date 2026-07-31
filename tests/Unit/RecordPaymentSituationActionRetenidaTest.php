<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordPaymentSituationActionRetenidaTest extends TestCase
{
    use RefreshDatabase;

    private RecordPaymentSituationAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(RecordPaymentSituationAction::class);

        // ScholarshipLoggingService::log() resolves the actor via auth()->id();
        // an authenticated actor is required for the log write inside the
        // action's transaction to satisfy the NOT NULL performed_by_id column.
        $this->actingAs(User::factory()->create(['user_type' => 'ADMIN', 'active' => true]));
    }

    private function makeRefrend(float $baseAmount = 1000.00, array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 1,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => $baseAmount,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => $baseAmount,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    /** @test */
    public function percentage_mode_calculates_discount_and_final_amount(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'    => 'RETENIDA',
            'withholding_mode'   => 'percentage',
            'withholding_value'  => 30,
            'resolution_cause'   => 'BAJO_PROMEDIO',
        ], $refrend->user_id);

        $this->assertSame('300.00', $result->discount_amount);
        $this->assertSame('700.00', $result->final_amount);
        $this->assertSame('30.00', $result->discount_percentage);
        $this->assertSame(RefrendStatus::WITHHELD, $result->status);
        $this->assertSame('percentage', $result->withholding_mode);
        $this->assertSame('30.00', $result->withholding_value);
    }

    /** @test */
    public function fixed_mode_calculates_discount_below_base(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'    => 'RETENIDA',
            'withholding_mode'   => 'fixed',
            'withholding_value'  => 450,
            'resolution_cause'   => 'OTRO',
        ], $refrend->user_id);

        $this->assertSame('450.00', $result->discount_amount);
        $this->assertSame('550.00', $result->final_amount);
        $this->assertSame('fixed', $result->withholding_mode);
    }

    /** @test */
    public function fixed_mode_clamps_to_base_amount(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'    => 'RETENIDA',
            'withholding_mode'   => 'fixed',
            'withholding_value'  => 5000,
            'resolution_cause'   => 'OTRO',
        ], $refrend->user_id);

        $this->assertSame('1000.00', $result->discount_amount);
        $this->assertSame('0.00', $result->final_amount);
    }

    /** @test */
    public function invariant_final_plus_discount_equals_base(): void
    {
        $refrend = $this->makeRefrend(1234.56);

        $result = $this->action->execute($refrend, [
            'resolution_type'    => 'RETENIDA',
            'withholding_mode'   => 'percentage',
            'withholding_value'  => 37,
            'resolution_cause'   => 'BAJO_PROMEDIO',
        ], $refrend->user_id);

        $this->assertEqualsWithDelta(
            (float) $refrend->base_amount,
            (float) $result->final_amount + (float) $result->discount_amount,
            0.01
        );
    }

    /** @test */
    public function defaults_to_full_percentage_retention_for_backward_compatibility(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        // No withholding_mode/withholding_value in payload — mirrors historical
        // records/clients that predate this feature.
        $result = $this->action->execute($refrend, [
            'resolution_type'  => 'RETENIDA',
            'resolution_cause' => 'BAJO_PROMEDIO',
        ], $refrend->user_id);

        $this->assertSame('1000.00', $result->discount_amount);
        $this->assertSame('0.00', $result->final_amount);
        $this->assertSame('percentage', $result->withholding_mode);
    }
}
