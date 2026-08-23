<?php

namespace Tests\Unit;

use App\Actions\Scholarship\ClearRefrendResolutionAction;
use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendIncident;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearRefrendResolutionActionTest extends TestCase
{
    use RefreshDatabase;

    private ClearRefrendResolutionAction $action;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(ClearRefrendResolutionAction::class);
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

    /** A resolved (LISTO_PARA_PAGO), unlocked, unencumbered refrend with every
     *  resolution field populated — the baseline for undo assertions. */
    private function makeResolvedRefrend(User $user, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 3,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'resolution_type'              => 'BECA_MES',
            'resolution_cause'             => 'BAJO_PROMEDIO',
            'resolution_notes'             => 'Nota de resolución.',
            'suspension_percentage'        => null,
            'withholding_mode'             => null,
            'withholding_value'            => null,
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'refund_amount_from_previous'  => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_gross_amount'        => 1000.00,
            'snapshot_monto_apoyo'         => 0,
            'snapshot_discount_percentage' => null,
            'snapshot_discount_reason'     => null,
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
            'notified_at'                  => null,
            'notified_by_id'               => null,
            'notification_method'          => null,
            'atencion_reviewed_by_id'      => null,
            'atencion_reviewed_at'         => null,
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

    // ── Guard 1: lock ─────────────────────────────────────────────────────────

    /** @test */
    public function rejects_undo_when_locked_at_is_set(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeResolvedRefrend($user, ['locked_at' => now()]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('El refrendo está bloqueado y no admite cambios.');

        $this->action->execute($refrend, $this->admin->id);
    }

    /** @test */
    public function rejects_undo_when_legacy_status_is_authorized_while_listo_para_pago(): void
    {
        // isLocked() short-circuits to false for LISTO_PARA_PAGO before ever
        // reaching its legacy-enum branch — this is the exact branch D1 re-applies.
        $user    = $this->makeBecario();
        $refrend = $this->makeResolvedRefrend($user, ['status' => RefrendStatus::AUTHORIZED->value]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('El refrendo está bloqueado y no admite cambios.');

        $this->action->execute($refrend, $this->admin->id);
    }

    // ── Guard 2: precondition ─────────────────────────────────────────────────

    /** @test */
    public function rejects_undo_when_workflow_status_is_draft(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeResolvedRefrend($user, ['workflow_status' => 'DRAFT']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Solo se puede deshacer la resolución de refrendos en estado LISTO_PARA_PAGO.');

        $this->action->execute($refrend, $this->admin->id);
    }

    /** @test */
    public function rejects_undo_when_workflow_status_is_closed(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeResolvedRefrend($user, ['workflow_status' => 'CLOSED']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('El refrendo está bloqueado y no admite cambios.');

        $this->action->execute($refrend, $this->admin->id);
    }

    // ── Guard 3 & 4: ledger integrity ────────────────────────────────────────

    /** @test */
    public function rejects_undo_when_retention_has_payments_applied(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type' => 'RETENIDA',
            'status'          => RefrendStatus::WITHHELD->value,
        ]);

        ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $refrend->id,
            'period_year'       => 2026,
            'period_month'      => 3,
            'withheld_amount'   => 300.00,
            'paid_amount'       => 150.00,
            'status'            => 'PENDING',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('No se puede modificar una retención que ya tiene pagos aplicados.');

        $this->action->execute($refrend, $this->admin->id);
    }

    /** @test */
    public function rejects_undo_when_an_active_abono_is_applied_to_this_refrend(): void
    {
        $user          = $this->makeBecario();
        $refrend       = $this->makeResolvedRefrend($user);
        $otherRefrend  = $this->makeResolvedRefrend($this->makeBecario(), ['period_month' => 2]);

        $otherLedger = ScholarshipWithholding::create([
            'user_id'           => $otherRefrend->user_id,
            'origin_refrend_id' => $otherRefrend->id,
            'period_year'       => 2026,
            'period_month'      => 2,
            'withheld_amount'   => 300.00,
            'paid_amount'       => 100.00,
            'status'            => 'PENDING',
        ]);

        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $otherLedger->id,
            'applied_refrend_id' => $refrend->id,
            'amount'             => 100.00,
            'created_by_id'      => $this->admin->id,
            'is_voided'          => false,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Este refrendo ya tiene abonos aplicados. Revierta los abonos antes de deshacer la resolución.');

        $this->action->execute($refrend, $this->admin->id);
    }

    // ── Successful undo per resolution type ─────────────────────────────────

    /** @test */
    public function undoes_beca_mes_and_returns_to_draft(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('DRAFT', $result->workflow_status);
        $this->assertNull($result->resolution_type);
    }

    /** @test */
    public function undoes_sin_pago_and_returns_to_draft(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type' => 'SIN_PAGO',
            'discount_percentage' => 100,
            'final_amount' => 0,
        ]);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('DRAFT', $result->workflow_status);
        $this->assertNull($result->resolution_type);
    }

    /** @test */
    public function undoes_retenida_with_no_payments_cancels_the_ledger_row(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type'   => 'RETENIDA',
            'status'            => RefrendStatus::WITHHELD->value,
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30.00,
        ]);

        $ledger = ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $refrend->id,
            'period_year'       => 2026,
            'period_month'      => 3,
            'withheld_amount'   => 300.00,
            'paid_amount'       => 0,
            'status'            => 'PENDING',
        ]);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('DRAFT', $result->workflow_status);
        $this->assertSame('CANCELLED', $ledger->fresh()->status);
    }

    /** @test */
    public function undoes_suspendida_and_returns_to_draft(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type'       => 'SUSPENDIDA',
            'suspension_percentage' => 50,
        ]);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('DRAFT', $result->workflow_status);
        $this->assertNull($result->resolution_type);
    }

    // ── Field reset correctness ──────────────────────────────────────────────

    /** @test */
    public function resets_every_resolution_field_to_its_exact_undo_value(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type'             => 'SUSPENDIDA',
            'resolution_cause'            => 'BAJO_PROMEDIO',
            'resolution_notes'            => 'Notas.',
            'suspension_percentage'       => 50,
            'refund_amount_from_previous' => 275.50,
            'notified_at'                 => now(),
            'notified_by_id'              => $this->admin->id,
            'notification_method'         => 'EMAIL',
        ]);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertNull($result->resolution_type);
        $this->assertNull($result->resolution_cause);
        $this->assertNull($result->resolution_notes);
        $this->assertSame(RefrendStatus::DRAFT, $result->status);
        $this->assertNull($result->suspension_percentage);
        $this->assertNull($result->withholding_mode);
        $this->assertNull($result->withholding_value);
        // NOT NULL decimal(10,2) column — must be '0.00', never null.
        $this->assertSame('0.00', $result->refund_amount_from_previous);
        $this->assertNull($result->notified_at);
        $this->assertNull($result->notified_by_id);
        $this->assertNull($result->notification_method);
        $this->assertSame($this->admin->id, $result->atencion_reviewed_by_id);
        $this->assertNotNull($result->atencion_reviewed_at);
    }

    // ── Automatic penalty re-derivation ──────────────────────────────────────

    /** @test */
    public function recreates_retardos_discount_when_still_earned(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $generation = $this->makeGeneration();
        $classOne   = $this->makeClass($generation->id, '2026-02-10');
        $classTwo   = $this->makeClass($generation->id, '2026-02-11');
        $this->makeAttendance($user->id, $classOne->id, 'LATE');
        $this->makeAttendance($user->id, $classTwo->id, 'LATE');

        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('DRAFT', $result->workflow_status);
        $this->assertSame(
            1,
            $result->discounts()->where('discount_type', 'RETARDOS')->count(),
            'Two unconsumed retardos in the current semester must re-earn the RETARDOS discount.'
        );
    }

    /** @test */
    public function recreates_falta_injustificada_discount_when_still_earned(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $generation = $this->makeGeneration();
        $class      = $this->makeClass($generation->id, '2026-03-15');
        $this->makeAttendance($user->id, $class->id, 'ABSENT');

        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('DRAFT', $result->workflow_status);
        $this->assertSame(
            1,
            $result->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->count(),
            'An unjustified absence in the refrend month must re-earn the FALTA_INJUSTIFICADA discount.'
        );
    }

    /** @test */
    public function does_not_recreate_penalty_discounts_when_no_longer_earned(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame(0, $result->discounts()->whereIn('discount_type', ['RETARDOS', 'FALTA_INJUSTIFICADA'])->count());
    }

    // ── Final workflow_status ─────────────────────────────────────────────────

    /** @test */
    public function final_workflow_status_is_con_incidencia_when_a_profile_discount_is_active(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user, [
            'active_discount_percentage' => 20,
            'discount_valid_from'        => now()->subDay()->toDateString(),
            'discount_valid_until'       => now()->addMonths(3)->toDateString(),
        ]);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
        $this->assertSame(
            1,
            ScholarshipRefrendIncident::where('scholarship_refrend_id', $result->id)
                ->where('incident_type', 'DESCUENTO_PERFIL')
                ->where('is_resolved', false)
                ->count()
        );
    }

    // ── Audit trail ───────────────────────────────────────────────────────────

    /** @test */
    public function logs_resolution_cleared_with_old_resolution_type_transitioning_to_null(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        $this->action->execute($refrend, $this->admin->id);

        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', 'RESOLUTION_CLEARED')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('BECA_MES', $log->old_values['resolution_type']);
        $this->assertNull($log->new_values['resolution_type']);
    }

    // ── D2 regression: no duplicate DESCUENTO_PERFIL incidents ──────────────

    /** @test */
    public function two_consecutive_apply_undo_cycles_produce_at_most_one_unresolved_descuento_perfil_incident(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user, [
            'active_discount_percentage' => 20,
            'discount_valid_from'        => now()->subDay()->toDateString(),
            'discount_valid_until'       => now()->addMonths(3)->toDateString(),
        ]);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        // Cycle 1: undo → CON_INCIDENCIA with one auto-generated incident.
        $afterFirstUndo = $this->action->execute($refrend, $this->admin->id);
        $this->assertSame('CON_INCIDENCIA', $afterFirstUndo->workflow_status);

        // Re-resolve (mirrors a real apply) then undo a second time.
        $recordAction = $this->app->make(RecordPaymentSituationAction::class);
        $recordAction->execute($afterFirstUndo->fresh(), ['resolution_type' => 'BECA_MES'], $this->admin->id);

        $afterSecondUndo = $this->action->execute($afterFirstUndo->fresh(), $this->admin->id);

        $this->assertSame('CON_INCIDENCIA', $afterSecondUndo->workflow_status);
        $this->assertSame(
            1,
            ScholarshipRefrendIncident::where('scholarship_refrend_id', $afterSecondUndo->id)
                ->where('incident_type', 'DESCUENTO_PERFIL')
                ->where('is_resolved', false)
                ->count(),
            'A second apply→undo cycle must not accumulate a duplicate DESCUENTO_PERFIL incident.'
        );
    }

    /** @test */
    public function a_human_filed_descuento_perfil_incident_survives_recalculation(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        ScholarshipRefrendIncident::create([
            'scholarship_refrend_id' => $refrend->id,
            'incident_category'      => 'ACADEMICO',
            'incident_type'          => 'DESCUENTO_PERFIL',
            'description'            => 'Incidencia registrada manualmente.',
            'priority'               => 'LOW',
            'created_by_id'          => $this->admin->id,
            'is_resolved'            => false,
        ]);

        $this->action->execute($refrend, $this->admin->id);

        $this->assertSame(
            1,
            ScholarshipRefrendIncident::where('scholarship_refrend_id', $refrend->id)
                ->where('incident_type', 'DESCUENTO_PERFIL')
                ->where('created_by_id', $this->admin->id)
                ->count(),
            'The D2 dedupe delete must only ever remove machine-generated (created_by_id IS NULL) incidents.'
        );
    }
}
