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

    // ── Zero amount → the EXPECTED outcome, never needs a reason ─────────────
    //
    // The becario already received this month's money via the advance batch
    // back at the origin refrend. Paying nothing now is the safe, expected
    // action (design correction, live user feedback 2026-09-25) — it must
    // NEVER require a divergence reason, regardless of whether one is
    // supplied. The old logic compared the resolution's amount against the
    // ALREADY-PAID amount and required a reason for anything that DIFFERED —
    // backwards: it let a full re-payment through silently and demanded an
    // explanation for the safe $0 case.

    /** @test */
    public function resolving_with_sin_pago_marks_the_advance_month_reached_without_requiring_a_reason(): void
    {
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, ['resolution_type' => 'SIN_PAGO'], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('REACHED', $advanceMonth->status);
        $this->assertSame('SIN_PAGO', $advanceMonth->settled_resolution_type);
        $this->assertNull($advanceMonth->divergence_reason);
        $this->assertNotNull($advanceMonth->reached_at);
    }

    /** @test */
    public function a_baja_definitiva_resolution_never_diverges_since_it_pays_nothing(): void
    {
        // BAJA_DEFINITIVA always sets final_amount to 0.00 — same zero-amount
        // case as SIN_PAGO, so it must never require a reason either.
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, [
            'resolution_type'  => 'BAJA_DEFINITIVA',
            'resolution_cause' => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('REACHED', $advanceMonth->status);
        $this->assertSame('BAJA_DEFINITIVA', $advanceMonth->settled_resolution_type);
        $this->assertNull($advanceMonth->divergence_reason);
    }

    /** @test */
    public function retenida_at_100_percent_does_not_diverge_since_nothing_is_paid_now(): void
    {
        // A full (100%) withholding also reduces final_amount to 0.00 — this
        // is the boundary between RETENIDA's two behaviors (see the partial
        // case below, which DOES diverge).
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 100,
        ], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('REACHED', $advanceMonth->status);
        $this->assertSame('RETENIDA', $advanceMonth->settled_resolution_type);
        $this->assertNull($advanceMonth->divergence_reason);
    }

    // ── Non-zero amount → divergence, a reason is required ───────────────────
    //
    // Any resolution that pays the becario something THIS month, on top of
    // what they already received via the advance, is the risky case (real
    // double-payment risk) — this is what must require an explicit reason.

    /** @test */
    public function diverging_with_a_non_zero_amount_without_a_reason_is_rejected(): void
    {
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/pagado por adelantado/');

        try {
            $this->action->execute($refrend, ['resolution_type' => 'BECA_MES'], $this->admin->id);
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

    /** @test */
    public function a_non_zero_amount_with_a_reason_marks_the_advance_month_overridden(): void
    {
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $result = $this->action->execute($refrend, [
            'resolution_type'           => 'BECA_MES',
            'advance_divergence_reason' => 'Solicitud especial de dirección, se autoriza pago completo adicional.',
        ], $this->admin->id);

        $this->assertSame('1000.00', $result->final_amount);

        $advanceMonth->refresh();
        $this->assertSame('OVERRIDDEN', $advanceMonth->status);
        $this->assertSame('BECA_MES', $advanceMonth->settled_resolution_type);
        $this->assertSame(
            'Solicitud especial de dirección, se autoriza pago completo adicional.',
            $advanceMonth->divergence_reason
        );
        $this->assertNotNull($advanceMonth->reached_at);
    }

    // ── Zero restriction: spot-check other resolution types ─────────────────

    /** @test */
    public function an_egresado_resolution_diverges_and_requires_a_reason(): void
    {
        // EGRESADO is financially identical to BECA_MES (full dueAmount) —
        // same divergence rule applies.
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, [
            'resolution_type'           => 'EGRESADO',
            'advance_divergence_reason' => 'Egresó el mismo mes del adelanto, se paga de todas formas.',
        ], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('OVERRIDDEN', $advanceMonth->status);
        $this->assertSame('EGRESADO', $advanceMonth->settled_resolution_type);
        $this->assertSame(
            'Egresó el mismo mes del adelanto, se paga de todas formas.',
            $advanceMonth->divergence_reason
        );
    }

    /** @test */
    public function a_diverging_retenida_resolution_is_accepted_with_a_reason(): void
    {
        // A PARTIAL (50%) withholding leaves final_amount at 500.00 — a
        // genuine non-zero payment this month, unlike the 100% case above.
        $refrend      = $this->makeRefrend(1000.00);
        $advanceMonth = $this->linkAdvanceMonth($refrend, 1000.00);

        $this->action->execute($refrend, [
            'resolution_type'           => 'RETENIDA',
            'withholding_mode'          => 'percentage',
            'withholding_value'         => 50,
            'advance_divergence_reason' => 'Retención parcial por incidencia administrativa.',
        ], $this->admin->id);

        $advanceMonth->refresh();
        $this->assertSame('OVERRIDDEN', $advanceMonth->status);
        $this->assertSame('RETENIDA', $advanceMonth->settled_resolution_type);
        $this->assertSame('Retención parcial por incidencia administrativa.', $advanceMonth->divergence_reason);
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
