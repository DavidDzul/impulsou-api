<?php

namespace Tests\Unit;

use App\Actions\Scholarship\ClearRefrendResolutionAction;
use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordPaymentSituationActionDescuentoDefinitivoTest extends TestCase
{
    use RefreshDatabase;

    private RecordPaymentSituationAction $action;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(RecordPaymentSituationAction::class);
        $this->admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);
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

    private function makeProfile(User $user, array $overrides = []): ScholarshipProfile
    {
        return ScholarshipProfile::create(array_merge([
            'user_id'                    => $user->id,
            'scholarship_type'           => ScholarshipType::IU->value,
            'monthly_amount'             => 1000.00,
            'payment_start_date'         => now()->subYear()->toDateString(),
            'active_discount_percentage' => null,
            'discount_valid_until'       => null,
        ], $overrides));
    }

    // ── Discount math ─────────────────────────────────────────────────────────

    /** @test */
    public function percentage_mode_calculates_discount_and_final_amount(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'    => 'DESCUENTO_DEFINITIVO',
            'withholding_mode'   => 'percentage',
            'withholding_value'  => 20,
            'resolution_cause'   => 'OTRO',
        ], $refrend->user_id);

        $this->assertSame('200.00', $result->discount_amount);
        $this->assertSame('800.00', $result->final_amount);
        $this->assertSame('20.00', $result->discount_percentage);
        $this->assertSame('LISTO_PARA_PAGO', $result->workflow_status);
        $this->assertSame('DESCUENTO_DEFINITIVO', $result->resolution_type);
    }

    /** @test */
    public function fixed_mode_clamps_to_due_amount(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'    => 'DESCUENTO_DEFINITIVO',
            'withholding_mode'   => 'fixed',
            'withholding_value'  => 5000,
            'resolution_cause'   => 'OTRO',
        ], $refrend->user_id);

        $this->assertSame('1000.00', $result->discount_amount);
        $this->assertSame('0.00', $result->final_amount);
    }

    // ── Parity with RETENIDA ─────────────────────────────────────────────────

    /** @test */
    public function produces_the_same_amounts_as_retenida_given_identical_inputs(): void
    {
        $retenidaRefrend         = $this->makeRefrend(1000.00);
        $descuentoDefinitivoRefrend = $this->makeRefrend(1000.00);

        $retenidaResult = $this->action->execute($retenidaRefrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'fixed',
            'withholding_value' => 300,
            'resolution_cause'  => 'OTRO',
        ], $retenidaRefrend->user_id);

        $descuentoDefinitivoResult = $this->action->execute($descuentoDefinitivoRefrend, [
            'resolution_type'   => 'DESCUENTO_DEFINITIVO',
            'withholding_mode'  => 'fixed',
            'withholding_value' => 300,
            'resolution_cause'  => 'OTRO',
        ], $descuentoDefinitivoRefrend->user_id);

        $this->assertSame($retenidaResult->final_amount, $descuentoDefinitivoResult->final_amount);
        $this->assertSame($retenidaResult->discount_amount, $descuentoDefinitivoResult->discount_amount);
        $this->assertSame($retenidaResult->discount_percentage, $descuentoDefinitivoResult->discount_percentage);
        $this->assertSame('700.00', $descuentoDefinitivoResult->final_amount);
        $this->assertSame('300.00', $descuentoDefinitivoResult->discount_amount);

        // Only the RETENIDA one carries the withholding side effects.
        $this->assertSame(RefrendStatus::WITHHELD, $retenidaResult->status);
        $this->assertNotSame(RefrendStatus::WITHHELD, $descuentoDefinitivoResult->status);
    }

    // ── Regression guards: no ledger, no WITHHELD status ─────────────────────

    /** @test */
    public function does_not_create_a_withholding_ledger_row(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'   => 'DESCUENTO_DEFINITIVO',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 40,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $refrend->user_id);

        $this->assertDatabaseMissing('scholarship_withholdings', [
            'origin_refrend_id' => $result->id,
        ]);
    }

    /** @test */
    public function does_not_set_status_to_withheld(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'   => 'DESCUENTO_DEFINITIVO',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 40,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $refrend->user_id);

        $this->assertNotSame(RefrendStatus::WITHHELD, $result->status);
        $this->assertSame(RefrendStatus::DRAFT, $result->status);
        $this->assertSame('LISTO_PARA_PAGO', $result->workflow_status);
    }

    /** @test */
    public function reclassifying_from_retenida_to_descuento_definitivo_cancels_the_prior_ledger_row(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $this->action->execute($refrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $refrend->user_id);

        $ledger = ScholarshipWithholding::where('origin_refrend_id', $refrend->id)->first();
        $this->assertNotNull($ledger);
        $this->assertSame('PENDING', $ledger->status);

        $this->action->execute($refrend->fresh(), [
            'resolution_type'   => 'DESCUENTO_DEFINITIVO',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $refrend->user_id);

        $this->assertSame('CANCELLED', $ledger->fresh()->status);
    }

    // ── Undo (generic path) ───────────────────────────────────────────────────

    /** @test */
    public function undo_reverses_a_descuento_definitivo_resolution_via_the_generic_clear_action(): void
    {
        $refrend = $this->makeRefrend(1000.00);
        $this->makeProfile(User::find($refrend->user_id));

        $resolved = $this->action->execute($refrend, [
            'resolution_type'   => 'DESCUENTO_DEFINITIVO',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 20,
            'resolution_cause'  => 'OTRO',
        ], $refrend->user_id);

        $this->assertSame('LISTO_PARA_PAGO', $resolved->workflow_status);

        $clearAction = $this->app->make(ClearRefrendResolutionAction::class);
        $undone      = $clearAction->execute($resolved->fresh(), $this->admin->id);

        $this->assertSame('DRAFT', $undone->workflow_status);
        $this->assertNull($undone->resolution_type);
        $this->assertNull($undone->resolution_cause);
        $this->assertNull($undone->withholding_mode);
        $this->assertNull($undone->withholding_value);
        $this->assertSame(RefrendStatus::DRAFT, $undone->status);
        $this->assertDatabaseMissing('scholarship_withholdings', [
            'origin_refrend_id' => $undone->id,
        ]);
    }
}
