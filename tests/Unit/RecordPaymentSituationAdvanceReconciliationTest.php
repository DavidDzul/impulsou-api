<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arrival reconciliation (design D4, "Arrival-time reconciliation" block in
 * RecordPaymentSituationAction). Proves the spec's non-negotiable
 * requirement: staff has FULL, UNRESTRICTED discretion at arrival — the
 * refrend row is a plain DRAFT row (PR1/PR3), reachable through the
 * unmodified 8-branch switch statement, independent of advance-paid
 * history. This PR's only job is annotating the linked
 * scholarship_advance_payment_months row afterward.
 */
class RecordPaymentSituationAdvanceReconciliationTest extends TestCase
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
            'period_year'                  => 2027,
            'period_month'                 => 6,
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

    /**
     * Links an advance-payment-month child row to $refrend (as if
     * RecordAdvancePaymentAction had created it, PR3). Uses the factory's
     * overrides so the linked row's user_id/period/refrend_id match the
     * refrend under test — the factory's own dummy header/user are
     * irrelevant to this reconciliation logic, which only looks up by
     * refrend_id.
     */
    private function linkAdvanceMonth(ScholarshipRefrend $refrend, float $amount): ScholarshipAdvancePaymentMonth
    {
        return ScholarshipAdvancePaymentMonth::factory()->create([
            'user_id'      => $refrend->user_id,
            'period_year'  => $refrend->period_year,
            'period_month' => $refrend->period_month,
            'amount'       => number_format($amount, 2, '.', ''),
            'refrend_id'   => $refrend->id,
            'status'       => 'PENDING',
        ]);
    }

    // ── Matching amount → REACHED ────────────────────────────────────────────

    /** @test */
    public function resolving_with_a_matching_amount_marks_the_advance_month_reached(): void
    {
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, ['resolution_type' => 'BECA_MES'], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('REACHED', $advanceMonth->status);
        $this->assertSame('BECA_MES', $advanceMonth->settled_resolution_type);
        $this->assertNull($advanceMonth->divergence_reason);
        $this->assertNotNull($advanceMonth->reached_at);
    }

    /** @test */
    public function zero_percent_resolution_is_just_as_valid_as_full_amount(): void
    {
        // Proves the spec's core constraint: SIN_PAGO (0%) after a 100%
        // advance is NOT special-cased or rejected — it is one more
        // divergent-but-allowed resolution, exactly like any other.
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'            => 'SIN_PAGO',
            'advance_divergence_reason'  => 'Becario reprobó el periodo adelantado.',
        ], $this->admin->id);

        $this->assertSame('0.00', $result->final_amount);

        $advanceMonth->refresh();
        $this->assertSame('OVERRIDDEN', $advanceMonth->status);
        $this->assertSame('SIN_PAGO', $advanceMonth->settled_resolution_type);
        $this->assertSame('Becario reprobó el periodo adelantado.', $advanceMonth->divergence_reason);
        $this->assertNotNull($advanceMonth->reached_at);
    }

    // ── Divergence without a reason is rejected ──────────────────────────────

    /** @test */
    public function diverging_from_the_advanced_amount_without_a_reason_is_rejected(): void
    {
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/pagado por adelantado/');

        try {
            $this->action->execute($refrend, ['resolution_type' => 'SIN_PAGO'], $this->admin->id);
        } finally {
            // Whole-transaction rollback: the refrend's own update must not
            // have survived either, proving this guard runs inside the same
            // DB::transaction as the rest of the action.
            $refrend->refresh();
            $this->assertSame('DRAFT', $refrend->workflow_status);

            $advanceMonth->refresh();
            $this->assertSame('PENDING', $advanceMonth->status);
            $this->assertNull($advanceMonth->settled_resolution_type);
        }
    }

    // ── Zero restriction: spot-check other resolution types ─────────────────

    /** @test */
    public function a_matching_egresado_resolution_is_accepted_and_annotated(): void
    {
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, ['resolution_type' => 'EGRESADO'], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('REACHED', $advanceMonth->status);
        $this->assertSame('EGRESADO', $advanceMonth->settled_resolution_type);
        $this->assertNull($advanceMonth->divergence_reason);
    }

    /** @test */
    public function a_diverging_retenida_resolution_is_accepted_with_a_reason(): void
    {
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, [
            'resolution_type'           => 'RETENIDA',
            'withholding_mode'          => 'percentage',
            'withholding_value'         => 100,
            'advance_divergence_reason' => 'Retención total por incidencia administrativa.',
        ], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('OVERRIDDEN', $advanceMonth->status);
        $this->assertSame('RETENIDA', $advanceMonth->settled_resolution_type);
        $this->assertSame('Retención total por incidencia administrativa.', $advanceMonth->divergence_reason);
    }

    /** @test */
    public function a_diverging_baja_definitiva_resolution_is_accepted_with_a_reason(): void
    {
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, [
            'resolution_type'           => 'BAJA_DEFINITIVA',
            'resolution_cause'          => 'BAJO_PROMEDIO',
            'advance_divergence_reason' => 'Baja definitiva posterior al adelanto.',
        ], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('OVERRIDDEN', $advanceMonth->status);
        $this->assertSame('BAJA_DEFINITIVA', $advanceMonth->settled_resolution_type);
        $this->assertSame('Baja definitiva posterior al adelanto.', $advanceMonth->divergence_reason);
    }

    // ── No-op for normal (non-advance) refrends ──────────────────────────────

    /** @test */
    public function resolving_a_normal_refrend_touches_no_advance_payment_month_row(): void
    {
        $refrend = $this->makeRefrend(1000.00);

        $this->action->execute($refrend, [
            'resolution_type'  => 'BAJA_DEFINITIVA',
            'resolution_cause' => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $this->assertSame(0, ScholarshipAdvancePaymentMonth::count());
    }

    /** @test */
    public function resolving_a_normal_refrend_is_byte_identical_to_pre_pr4_behavior(): void
    {
        // Verbatim regression guard: same scenario as
        // RecordPaymentSituationActionBajaDefinitivaTest::pays_nothing_this_month_and_cancels_the_refrend,
        // re-run here to prove the reconciliation block is a true no-op when
        // there is no linked advance-payment-month row.
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
}
