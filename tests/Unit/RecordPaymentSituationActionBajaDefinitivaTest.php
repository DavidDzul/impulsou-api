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

class RecordPaymentSituationActionBajaDefinitivaTest extends TestCase
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

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    // ── Payment effect (unchanged behavior) ──────────────────────────────────

    /** @test */
    public function pays_nothing_this_month_and_cancels_the_refrend(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'  => 'BAJA_DEFINITIVA',
            'resolution_cause' => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $this->assertSame('0.00', $result->final_amount);
        $this->assertSame('100.00', $result->discount_percentage);
        $this->assertSame('1000.00', $result->discount_amount);
        $this->assertSame(RefrendStatus::CANCELLED, $result->status);
        $this->assertSame('LISTO_PARA_PAGO', $result->workflow_status);
    }

    // ── User deactivation (new behavior, user-requested 2026-09-20) ─────────
    //
    // A permanent withdrawal must stop the becario from being generated any
    // further refrends — GenerateMonthlyRefrendsService only considers
    // active=true users, same mechanism reticula_end_date already relies on.

    /** @test */
    public function deactivates_the_becario_user_account(): void
    {
        $refrend = $this->makeRefrend(1000.00);
        $user    = $refrend->user;
        $this->assertTrue((bool) $user->active, 'Sanity check: user starts active.');

        $this->action->execute($refrend, [
            'resolution_type'  => 'BAJA_DEFINITIVA',
            'resolution_cause' => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $this->assertFalse((bool) $user->fresh()->active);
    }

    /** @test */
    public function does_not_deactivate_the_user_for_other_resolution_types(): void
    {
        $refrend = $this->makeRefrend(1000.00);
        $user    = $refrend->user;

        $this->action->execute($refrend, [
            'resolution_type' => 'BECA_MES',
        ], $this->admin->id);

        $this->assertTrue((bool) $user->fresh()->active);
    }
}
