<?php

namespace Tests\Unit;

use App\Actions\Scholarship\ClearRefrendResolutionAction;
use App\Actions\Scholarship\FlagRefrendIncidentAction;
use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendIncident;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use App\Services\RecalculateRefrendService;
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
            'withholding_mode'             => null,
            'withholding_value'            => null,
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

    // ── sdd/egresado-baja-definitiva, design D5/spec "Rejected by
    // reclassification and undo" (PR2a task 2a.10): confirms a refrend
    // actually closed via CloseScholarshipCycleAction (not just a raw
    // CLOSED fixture) is rejected by undo — end-to-end through
    // RecordPaymentSituationAction, not a hand-built row. ────────────────

    /** @test */
    public function rejects_undo_after_an_egresado_cut(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type' => null,
            'workflow_status' => 'DRAFT',
        ]);

        $recordAction = $this->app->make(RecordPaymentSituationAction::class);
        $closed = $recordAction->execute($refrend, ['resolution_type' => 'EGRESADO'], $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('El refrendo está bloqueado y no admite cambios.');

        $this->action->execute($closed, $this->admin->id);
    }

    /** @test */
    public function rejects_undo_after_a_baja_definitiva_cut(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type' => null,
            'workflow_status' => 'DRAFT',
        ]);

        $recordAction = $this->app->make(RecordPaymentSituationAction::class);
        $closed = $recordAction->execute($refrend, ['resolution_type' => 'BAJA_DEFINITIVA'], $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('El refrendo está bloqueado y no admite cambios.');

        $this->action->execute($closed, $this->admin->id);
    }

    /** @test */
    public function rejects_reclassification_after_an_egresado_cut(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type' => null,
            'workflow_status' => 'DRAFT',
        ]);

        $recordAction = $this->app->make(RecordPaymentSituationAction::class);
        $closed = $recordAction->execute($refrend, ['resolution_type' => 'EGRESADO'], $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Solo se puede registrar la situación en refrendos en estado DRAFT, CON_INCIDENCIA o LISTO_PARA_PAGO.'
        );

        $recordAction->execute($closed, ['resolution_type' => 'BECA_MES'], $this->admin->id);
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

    // ── Field reset correctness ──────────────────────────────────────────────

    /** @test */
    public function resets_every_resolution_field_to_its_exact_undo_value(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, [
            'resolution_type'             => 'SIN_PAGO',
            'resolution_cause'            => 'BAJO_PROMEDIO',
            'resolution_notes'            => 'Notas.',
            'notified_at'                 => now(),
            'notified_by_id'              => $this->admin->id,
            'notification_method'         => 'EMAIL',
        ]);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertNull($result->resolution_type);
        $this->assertNull($result->resolution_cause);
        $this->assertNull($result->resolution_notes);
        $this->assertSame(RefrendStatus::DRAFT, $result->status);
        $this->assertNull($result->withholding_mode);
        $this->assertNull($result->withholding_value);
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
            // makeResolvedRefrend() uses a fixed period (2026-03). Vigencia is
            // evaluated against that period's start, not "today" (buildSnapshot
            // bug fix), so this range must cover 2026-03-01, not "now".
            'discount_valid_from'        => '2026-02-01',
            'discount_valid_until'       => '2026-06-01',
        ]);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
        $this->assertSame(
            1,
            ScholarshipRefrendIncident::where('scholarship_refrend_id', $result->id)
                ->where('incident_type', 'DESCUENTO_PERFIL')
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
            // See comment in the previous test — vigencia must cover the
            // refrend's fixed period (2026-03), not "today".
            'discount_valid_from'        => '2026-02-01',
            'discount_valid_until'       => '2026-06-01',
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

    // -- pagos-adelantados-doble-pago F4 --------------------------------------

    private function linkAdvanceMonth(ScholarshipRefrend $refrend, array $overrides = []): ScholarshipAdvancePaymentMonth
    {
        return ScholarshipAdvancePaymentMonth::factory()->create(array_merge([
            'user_id'      => $refrend->user_id,
            'period_year'  => $refrend->period_year,
            'period_month' => $refrend->period_month,
            'amount'       => '1000.00',
            'refrend_id'   => $refrend->id,
        ], $overrides));
    }

    /**
     * @test
     * @dataProvider settledAdvanceStates
     */
    public function clearing_resets_a_settled_advance_month_to_pending(string $status, string $type, ?string $reason): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => $type]);
        $month   = $this->linkAdvanceMonth($refrend, [
            'status'                  => $status,
            'settled_resolution_type' => $type,
            'divergence_reason'       => $reason,
            'reached_at'              => now(),
        ]);

        $this->action->execute($refrend, $this->admin->id);

        $month->refresh();
        $this->assertSame('PENDING', $month->status);
        $this->assertNull($month->settled_resolution_type);
        $this->assertNull($month->divergence_reason);
        $this->assertNull($month->reached_at);
    }

    public function settledAdvanceStates(): array
    {
        return [
            'reached'    => ['REACHED', 'SIN_PAGO', null],
            'overridden' => ['OVERRIDDEN', 'BECA_MES', 'Pago adicional autorizado por dirección.'],
        ];
    }

    /** @test */
    public function clearing_leaves_an_already_pending_advance_month_untouched(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user);
        $month   = $this->linkAdvanceMonth($refrend, ['status' => 'PENDING']);

        $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('PENDING', $month->fresh()->status);
    }

    // -- recalculation keeps the incident status -------------------------------

    private function makeHumanIncident(ScholarshipRefrend $refrend): ScholarshipRefrendIncident
    {
        return ScholarshipRefrendIncident::create([
            'scholarship_refrend_id' => $refrend->id,
            'incident_category'      => 'ACADEMICO',
            'incident_type'          => 'OTRO',
            'description'            => 'Incidencia registrada por un humano.',
            'priority'               => 'LOW',
            'created_by_id'          => $this->admin->id,
        ]);
    }

    /** @test */
    public function undoing_an_advance_payment_resolution_keeps_con_incidencia_when_a_human_incident_exists(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend  = $this->makeResolvedRefrend($user, ['workflow_status' => 'CON_INCIDENCIA', 'resolution_type' => null]);
        $incident = $this->makeHumanIncident($refrend);
        $this->linkAdvanceMonth($refrend, ['status' => 'PENDING']);

        $this->app->make(RecordPaymentSituationAction::class)
            ->execute($refrend->fresh(), ['resolution_type' => 'SIN_PAGO'], $this->admin->id);
        $this->assertSame('LISTO_PARA_PAGO', $refrend->fresh()->workflow_status);

        $result = $this->action->execute($refrend->fresh(), $this->admin->id);

        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
        $this->assertNotNull(ScholarshipRefrendIncident::find($incident->id));
    }

    /** @test */
    public function undoing_a_beca_mes_resolution_keeps_con_incidencia_when_a_human_incident_exists(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend  = $this->makeResolvedRefrend($user, ['workflow_status' => 'CON_INCIDENCIA', 'resolution_type' => null]);
        $incident = $this->makeHumanIncident($refrend);

        $this->app->make(RecordPaymentSituationAction::class)
            ->execute($refrend->fresh(), ['resolution_type' => 'BECA_MES'], $this->admin->id);

        $result = $this->action->execute($refrend->fresh(), $this->admin->id);

        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
        $this->assertNotNull(ScholarshipRefrendIncident::find($incident->id));
    }

    /** @test */
    public function full_recalculate_of_a_draft_refrend_with_a_human_incident_is_con_incidencia(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['workflow_status' => 'DRAFT', 'resolution_type' => null]);
        $this->makeHumanIncident($refrend);

        $result = $this->app->make(RecalculateRefrendService::class)->fullRecalculate($refrend->fresh());

        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
    }

    /** @test */
    public function a_human_flag_over_the_machine_descuento_perfil_incident_survives_recalculation(): void
    {
        $user    = $this->makeBecario();
        $profile = $this->makeProfile($user, [
            'active_discount_percentage' => 20,
            'discount_valid_from'        => '2026-02-01',
            'discount_valid_until'       => '2026-06-01',
        ]);
        $refrend = $this->makeResolvedRefrend($user, ['workflow_status' => 'DRAFT', 'resolution_type' => null]);
        $service = $this->app->make(RecalculateRefrendService::class);

        // The recalculation creates the machine incident (created_by_id NULL).
        $refrend = $service->fullRecalculate($refrend->fresh());
        $machine = $refrend->incidents()->where('incident_type', 'DESCUENTO_PERFIL')->firstOrFail();
        $this->assertNull($machine->created_by_id);

        // A human flags over it, keeping the same type.
        $this->app->make(FlagRefrendIncidentAction::class)->execute($refrend->fresh(), [
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'DESCUENTO_PERFIL',
            'description'       => 'Revisado por atención.',
            'priority'          => 'HIGH',
        ], '', $this->admin->id);
        $this->assertSame($this->admin->id, $machine->fresh()->created_by_id);

        // Discount removed, refrend back to DRAFT, recalculated.
        $profile->update(['active_discount_percentage' => null, 'discount_valid_from' => null, 'discount_valid_until' => null]);
        $refrend->fresh()->update(['workflow_status' => 'DRAFT']);
        $result = $service->fullRecalculate($refrend->fresh());

        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
        $this->assertSame($this->admin->id, ScholarshipRefrendIncident::findOrFail($machine->id)->created_by_id);
    }

    private function makeDiscountProfile(User $user): ScholarshipProfile
    {
        return $this->makeProfile($user, [
            'active_discount_percentage' => 20,
            'discount_valid_from'        => '2026-02-01',
            'discount_valid_until'       => '2026-06-01',
        ]);
    }

    /** @test */
    public function a_human_incident_supersedes_the_machine_discount_incident_keeping_one_row(): void
    {
        $user = $this->makeBecario();
        $this->makeDiscountProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['workflow_status' => 'DRAFT', 'resolution_type' => null]);
        $human   = $this->makeHumanIncident($refrend);

        $result = $this->app->make(RecalculateRefrendService::class)->fullRecalculate($refrend->fresh());

        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
        $this->assertGreaterThan(0, (float) $result->snapshot_discount_percentage);
        $this->assertSame([$human->id], $result->incidents()->pluck('id')->all());
    }

    /** @test */
    public function undoing_a_resolution_with_a_human_incident_and_active_discount_keeps_one_incident(): void
    {
        $user = $this->makeBecario();
        $this->makeDiscountProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['workflow_status' => 'CON_INCIDENCIA', 'resolution_type' => null]);
        $human   = $this->makeHumanIncident($refrend);
        $this->app->make(RecordPaymentSituationAction::class)
            ->execute($refrend->fresh(), ['resolution_type' => 'BECA_MES'], $this->admin->id);

        $result = $this->action->execute($refrend->fresh(), $this->admin->id);

        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
        $this->assertSame([$human->id], $result->incidents()->pluck('id')->all());
    }

    /** @test */
    public function a_human_flag_with_a_different_type_over_the_machine_row_leaves_one_incident_after_recalculation(): void
    {
        $user = $this->makeBecario();
        $this->makeDiscountProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['workflow_status' => 'DRAFT', 'resolution_type' => null]);
        $service = $this->app->make(RecalculateRefrendService::class);

        $refrend = $service->fullRecalculate($refrend->fresh());
        $this->assertSame(1, $refrend->incidents()->count());

        $this->app->make(FlagRefrendIncidentAction::class)->execute($refrend->fresh(), [
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'OTRO',
            'description'       => 'Revisado por atención.',
            'priority'          => 'HIGH',
        ], '', $this->admin->id);

        $refrend->fresh()->update(['workflow_status' => 'DRAFT']);
        $result = $service->fullRecalculate($refrend->fresh());

        $incidents = $result->incidents()->get();
        $this->assertCount(1, $incidents);
        $this->assertSame($this->admin->id, $incidents->first()->created_by_id);
        $this->assertSame('OTRO', $incidents->first()->incident_type);
        $this->assertSame('CON_INCIDENCIA', $result->workflow_status);
    }

    /** @test */
    public function discount_only_recalculation_twice_yields_exactly_one_machine_incident(): void
    {
        $user = $this->makeBecario();
        $this->makeDiscountProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['workflow_status' => 'DRAFT', 'resolution_type' => null]);
        $service = $this->app->make(RecalculateRefrendService::class);

        $refrend = $service->fullRecalculate($refrend->fresh());
        $refrend->fresh()->update(['workflow_status' => 'DRAFT']);
        $result = $service->fullRecalculate($refrend->fresh());

        $this->assertSame(1, $result->incidents()->count());
        $this->assertNull($result->incidents()->first()->created_by_id);
        $this->assertSame('DESCUENTO_PERFIL', $result->incidents()->first()->incident_type);
    }

    /** @test */
    public function undo_without_any_incident_or_discount_returns_to_draft(): void
    {
        $user = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeResolvedRefrend($user, ['resolution_type' => 'BECA_MES']);

        $result = $this->action->execute($refrend, $this->admin->id);

        $this->assertSame('DRAFT', $result->workflow_status);
        $this->assertSame(0, $result->incidents()->count());
    }
}
