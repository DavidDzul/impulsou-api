<?php

namespace Tests\Unit;

use App\Actions\Scholarship\ApproveFullPaymentAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipLateConsumption;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApproveFullPaymentActionTest extends TestCase
{
    use RefreshDatabase;

    private ApproveFullPaymentAction $action;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(ApproveFullPaymentAction::class);
        $this->admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeBecario(): User
    {
        return User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);
    }

    /** A DRAFT refrendo, unlocked, ready for "Pagar sin descuento por faltas". */
    private function makeDraftRefrend(User $user, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 3,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_gross_amount'        => 1000.00,
            'snapshot_monto_apoyo'         => 0,
            'snapshot_discount_percentage' => null,
            'snapshot_discount_reason'     => null,
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function makeGeneration(): Generation
    {
        return Generation::create([
            'generation_name'   => 'Test Gen',
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);
    }

    private function makeClass(int $generationId, string $date): ClassModel
    {
        return ClassModel::create([
            'name'          => 'Clase de prueba',
            'date'          => $date,
            'start_time'    => '08:00:00',
            'end_time'      => '09:00:00',
            'campus'        => 'MERIDA',
            'generation_id' => $generationId,
        ]);
    }

    private function makeAttendance(int $userId, int $classId, string $status): void
    {
        \App\Models\Attendance::create([
            'user_id'  => $userId,
            'class_id' => $classId,
            'status'   => $status,
        ]);
    }

    // ── Academic discount is never waivable ──────────────────────────────────

    /** @test */
    public function academic_discount_survives_and_final_amount_is_unchanged(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeDraftRefrend($user, [
            'snapshot_gross_amount'        => 3000.00,
            'snapshot_discount_percentage' => 50.00,
        ]);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('1500.00', $result->final_amount);
        $this->assertSame('50.00', $result->snapshot_discount_percentage);
    }

    // ── RETARDOS neutralization ───────────────────────────────────────────────

    /** @test */
    public function retardos_row_survives_at_zero_percent_with_its_late_consumptions_intact(): void
    {
        $user       = $this->makeBecario();
        $generation = $this->makeGeneration();
        $classOne   = $this->makeClass($generation->id, '2026-03-10');
        $classTwo   = $this->makeClass($generation->id, '2026-03-11');
        $this->makeAttendance($user->id, $classOne->id, 'LATE');
        $this->makeAttendance($user->id, $classTwo->id, 'LATE');

        $refrend = $this->makeDraftRefrend($user);
        $this->app->make(\App\Services\AttendancePenaltyService::class)
            ->applyPenaltyIfDue($refrend, $user, 25.0, \Carbon\Carbon::create(2026, 3, 15));

        $discount = $refrend->discounts()->where('discount_type', 'RETARDOS')->first();
        $this->assertNotNull($discount, 'Precondition: RETARDOS discount must exist before forgiveness.');
        $consumptionCountBefore = ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $discount->id)->count();
        $this->assertSame(2, $consumptionCountBefore);

        $this->action->execute($refrend->fresh(), $this->admin->id);

        $discount->refresh();
        $this->assertSame('0.00', $discount->discount_percentage);
        $this->assertStringContainsString('Condonado.', $discount->description);
        $this->assertSame(
            2,
            ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $discount->id)->count(),
            'Neutralizing must not delete the discount row nor cascade-delete its late consumptions.'
        );
    }

    /** @test */
    public function forgiven_retardo_does_not_re_trigger_next_month(): void
    {
        $user       = $this->makeBecario();
        $generation = $this->makeGeneration();
        $classOne   = $this->makeClass($generation->id, '2026-03-10');
        $classTwo   = $this->makeClass($generation->id, '2026-03-11');
        $this->makeAttendance($user->id, $classOne->id, 'LATE');
        $this->makeAttendance($user->id, $classTwo->id, 'LATE');

        $refrend = $this->makeDraftRefrend($user);
        $penaltyService = $this->app->make(\App\Services\AttendancePenaltyService::class);
        $penaltyService->applyPenaltyIfDue($refrend, $user, 25.0, \Carbon\Carbon::create(2026, 3, 15));

        $this->action->execute($refrend->fresh(), $this->admin->id);

        // Regression: the same 2 lates were marked consumed by applyPenaltyIfDue
        // and must remain consumed after forgiveness — applyPenaltyIfDue must
        // not re-earn the RETARDOS discount the following month.
        $nextMonthResult = $penaltyService->applyPenaltyIfDue(
            $refrend->fresh(),
            $user,
            25.0,
            \Carbon\Carbon::create(2026, 4, 15)
        );

        $this->assertNull($nextMonthResult);
    }

    // ── FALTA_INJUSTIFICADA neutralization ───────────────────────────────────

    /** @test */
    public function falta_injustificada_row_is_zeroed(): void
    {
        $user       = $this->makeBecario();
        $generation = $this->makeGeneration();
        $class      = $this->makeClass($generation->id, '2026-03-15');
        $this->makeAttendance($user->id, $class->id, 'ABSENT');

        $refrend = $this->makeDraftRefrend($user);
        $this->app->make(\App\Services\AttendancePenaltyService::class)
            ->applyAbsencePenaltyIfDue($refrend, $user, 2026, 3);

        $discount = $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->first();
        $this->assertNotNull($discount, 'Precondition: FALTA_INJUSTIFICADA discount must exist before forgiveness.');

        $this->action->execute($refrend->fresh(), $this->admin->id);

        $discount->refresh();
        $this->assertSame('0.00', $discount->discount_percentage);
    }

    // ── Lock guard ────────────────────────────────────────────────────────────

    /** @test */
    public function rejects_execution_when_refrend_is_locked(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeDraftRefrend($user, ['locked_at' => now()]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('El refrendo está bloqueado y no admite cambios.');

        $this->action->execute($refrend, $this->admin->id);
    }

    // ── Workflow status precondition ─────────────────────────────────────────

    /** @test */
    public function rejects_execution_when_workflow_status_is_listo_para_pago(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeDraftRefrend($user, ['workflow_status' => 'LISTO_PARA_PAGO']);

        $this->expectException(\DomainException::class);

        $this->action->execute($refrend, $this->admin->id);
    }
}
