<?php

namespace Tests\Unit;

use App\Actions\Scholarship\CloseScholarshipCycleAction;
use App\Enums\RefrendType;
use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Exceptions\CycleCutActiveTelmexCoverageException;
use App\Exceptions\CycleCutBlockedException;
use App\Exceptions\CycleCutPendingWithholdingsException;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresado-baja-definitiva, design D4/D10, task 2a.1/2a.11 (PR2a minimal
 * scope): marker set, CYCLE_ALREADY_CLOSED guard, GraduateBecarioAction
 * (EGRESADO) / active=false (BAJA_DEFINITIVA).
 *
 * PR2b (task 2b.8) adds the remaining guards here: blocking later months
 * (CYCLE_CUT_BLOCKED), own abonos (D9), ACTIVA Telmex coverage (amendment-3),
 * ANY pending withholding (amendment-4), and cascade-closing later open
 * refrends (D7).
 */
class CloseScholarshipCycleActionTest extends TestCase
{
    use RefreshDatabase;

    private CloseScholarshipCycleAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(CloseScholarshipCycleAction::class);
    }

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
        return ScholarshipProfile::factory()->create(array_merge([
            'user_id' => $user->id,
        ], $overrides));
    }

    private function makeRefrend(User $user, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 10,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::CANCELLED->value,
            'workflow_status'              => 'CLOSED',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 100,
            'discount_amount'              => 1000.00,
            'final_amount'                 => 0,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    /** @test */
    public function sets_the_stop_marker_on_the_profile_for_egresado(): void
    {
        $user    = $this->makeBecario();
        $profile = $this->makeProfile($user);
        $refrend = $this->makeRefrend($user);

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        $profile->refresh();
        $this->assertSame('2026-10-01', $profile->cycle_closed_period->toDateString());
        $this->assertSame('EGRESADO', $profile->cycle_closure_reason->value);
        $this->assertSame($refrend->id, $profile->cycle_closed_refrend_id);
    }

    /** @test */
    public function sets_the_stop_marker_on_the_profile_for_baja_definitiva(): void
    {
        $user    = $this->makeBecario();
        $profile = $this->makeProfile($user);
        $refrend = $this->makeRefrend($user, ['period_year' => 2026, 'period_month' => 5]);

        $this->action->execute($refrend, 'BAJA_DEFINITIVA', $user->id);

        $profile->refresh();
        $this->assertSame('2026-05-01', $profile->cycle_closed_period->toDateString());
        $this->assertSame('BAJA_DEFINITIVA', $profile->cycle_closure_reason->value);
    }

    /** @test */
    public function egresado_graduates_the_becario_and_keeps_active_true(): void
    {
        $user    = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeRefrend($user);

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        $fresh = $user->fresh();
        $this->assertSame('BEC_INACTIVE', $fresh->user_type);
        $this->assertTrue((bool) $fresh->active);
    }

    /** @test */
    public function baja_definitiva_deactivates_the_user_and_does_not_graduate(): void
    {
        $user    = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeRefrend($user);

        $this->action->execute($refrend, 'BAJA_DEFINITIVA', $user->id);

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->active);
        $this->assertSame('BEC_ACTIVE', $fresh->user_type);
    }

    /** @test */
    public function rejects_a_second_cut_on_an_already_closed_profile(): void
    {
        $user    = $this->makeBecario();
        $this->makeProfile($user, [
            'cycle_closed_period'     => '2026-09-01',
            'cycle_closure_reason'    => 'EGRESADO',
            'cycle_closed_refrend_id' => null,
        ]);
        $refrend = $this->makeRefrend($user);

        $this->expectException(ScholarshipCycleClosedException::class);

        $this->action->execute($refrend, 'BAJA_DEFINITIVA', $user->id);
    }

    /** @test */
    public function tolerates_a_missing_scholarship_profile_and_still_runs_the_user_effect(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user);

        $this->action->execute($refrend, 'BAJA_DEFINITIVA', $user->id);

        $this->assertFalse((bool) $user->fresh()->active);
        $this->assertNull(ScholarshipProfile::where('user_id', $user->id)->first());
    }

    // ── PR2b guards (task 2b.8) ─────────────────────────────────────────────

    private function makeAdvancePaymentMonth(User $user, ScholarshipRefrend $futureRefrend, string $status = 'PENDING'): ScholarshipAdvancePaymentMonth
    {
        $originRefrend = $this->makeRefrend($user, ['period_year' => 2025, 'period_month' => 1]);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => $originRefrend->period_year,
            'origin_period_month' => $originRefrend->period_month,
            'months_count'        => 1,
            'total_amount'        => 1000.00,
        ]);

        return ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header->id,
            'user_id'            => $user->id,
            'period_year'        => $futureRefrend->period_year,
            'period_month'       => $futureRefrend->period_month,
            'amount'             => 1000.00,
            'refrend_id'         => $futureRefrend->id,
            'status'             => $status,
        ]);
    }

    /** @test */
    public function rejects_the_cut_when_a_later_month_has_a_pending_advance_payment(): void
    {
        $user         = $this->makeBecario();
        $refrend      = $this->makeRefrend($user, ['period_month' => 6]);
        $laterRefrend = $this->makeRefrend($user, ['period_year' => 2027, 'period_month' => 2]);
        $this->makeAdvancePaymentMonth($user, $laterRefrend, 'PENDING');

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutBlockedException.');
        } catch (CycleCutBlockedException $e) {
            $this->assertSame(
                [['year' => 2027, 'month' => 2, 'reason' => 'ADVANCE_PAID']],
                $e->payload()['blocking_months']
            );
        }

        $this->assertNull(ScholarshipProfile::where('user_id', $user->id)->first());
    }

    /** @test */
    public function rejects_the_cut_when_a_later_refrend_is_already_paid_or_locked(): void
    {
        $user         = $this->makeBecario();
        $refrend      = $this->makeRefrend($user, ['period_month' => 6]);
        $laterRefrend = $this->makeRefrend($user, [
            'period_month'    => 7,
            'workflow_status' => 'PAID',
        ]);

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutBlockedException.');
        } catch (CycleCutBlockedException $e) {
            $this->assertSame(
                [['year' => 2026, 'month' => 7, 'reason' => 'PAID']],
                $e->payload()['blocking_months']
            );
        }

        $this->assertSame('PAID', $laterRefrend->fresh()->workflow_status);
    }

    /** @test */
    public function rejects_the_cut_when_a_later_refrend_already_has_abonos_applied(): void
    {
        $user         = $this->makeBecario();
        $refrend      = $this->makeRefrend($user, ['period_month' => 6]);
        $originRefrend = $this->makeRefrend($user, ['period_month' => 1]);
        $withholding  = ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $originRefrend->id,
            'period_year'       => $originRefrend->period_year,
            'period_month'      => $originRefrend->period_month,
            'withheld_amount'   => '300.00',
            'paid_amount'       => '300.00',
            'status'            => 'PAID',
        ]);
        $laterRefrend = $this->makeRefrend($user, [
            'period_month'    => 7,
            'workflow_status' => 'LISTO_PARA_PAGO',
        ]);
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $withholding->id,
            'applied_refrend_id' => $laterRefrend->id,
            'amount'             => '300.00',
            'created_by_id'      => $user->id,
        ]);

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutBlockedException.');
        } catch (CycleCutBlockedException $e) {
            $this->assertSame(
                [['year' => 2026, 'month' => 7, 'reason' => 'ABONOS_APPLIED']],
                $e->payload()['blocking_months']
            );
        }
    }

    /** @test */
    public function rejects_the_cut_when_the_cut_refrend_itself_already_applied_abonos_to_another_retention(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user, ['period_month' => 1]);
        $withholding   = ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $originRefrend->id,
            'period_year'       => $originRefrend->period_year,
            'period_month'      => $originRefrend->period_month,
            'withheld_amount'   => '300.00',
            'paid_amount'       => '300.00',
            'status'            => 'PAID',
        ]);
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $withholding->id,
            'applied_refrend_id' => $refrend->id,
            'amount'             => '300.00',
            'created_by_id'      => $user->id,
        ]);

        $this->expectException(\DomainException::class);

        $this->action->execute($refrend, 'EGRESADO', $user->id);
    }

    /** @test */
    public function rejects_the_cut_when_the_becario_has_an_activa_telmex_coverage(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        $coverage = TelmexCoverage::create([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => 'TELMEX',
            'start_period'                    => '2026-01-01',
            'status'                          => 'ACTIVA',
        ]);

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutActiveTelmexCoverageException.');
        } catch (CycleCutActiveTelmexCoverageException $e) {
            $this->assertSame([
                'coverage_id'  => $coverage->id,
                'status'       => 'ACTIVA',
                'start_period' => '2026-01-01',
            ], $e->payload());
        }
    }

    /** @test */
    public function does_not_block_on_telmex_coverage_in_en_cobro(): void
    {
        $user    = $this->makeBecario();
        $this->makeProfile($user);
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        TelmexCoverage::create([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => 'TELMEX',
            'start_period'                    => '2026-01-01',
            'status'                          => 'EN_COBRO',
        ]);

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        $this->assertNotNull(ScholarshipProfile::where('user_id', $user->id)->first()->cycle_closed_period);
    }

    /** @test */
    public function rejects_the_cut_when_any_pending_withholding_exists_even_out_of_the_payable_window(): void
    {
        $user          = $this->makeBecario();
        $refrend       = $this->makeRefrend($user, ['period_month' => 6]);
        $oldOrigin     = $this->makeRefrend($user, ['period_year' => 2020, 'period_month' => 1]);
        $oldWithholding = ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $oldOrigin->id,
            'period_year'       => 2020,
            'period_month'      => 1,
            'withheld_amount'   => '150.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutPendingWithholdingsException.');
        } catch (CycleCutPendingWithholdingsException $e) {
            $payload = $e->payload();
            $this->assertCount(1, $payload['withholdings']);
            $this->assertSame($oldWithholding->id, $payload['withholdings'][0]['id']);
            $this->assertSame('150.00', $payload['withholdings'][0]['remaining_amount']);
        }

        $this->assertNull(ScholarshipProfile::where('user_id', $user->id)->first());
    }

    private function makePendingLedger(User $user, ScholarshipRefrend $origin): ScholarshipWithholding
    {
        return ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $origin->id,
            'period_year'       => $origin->period_year,
            'period_month'      => $origin->period_month,
            'withheld_amount'   => '300.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);
    }

    /** @test */
    public function flags_the_cut_months_own_retention_and_points_staff_to_undo_it(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        $own     = $this->makePendingLedger($user, $refrend);

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutPendingWithholdingsException.');
        } catch (CycleCutPendingWithholdingsException $e) {
            $rows = $e->payload()['withholdings'];
            $this->assertCount(1, $rows);
            $this->assertSame($own->id, $rows[0]['id']);
            $this->assertTrue($rows[0]['is_current_refrend']);
            $this->assertSame(
                'Este mes tiene una retención registrada; deshaga la resolución de este mes antes de marcar egreso o baja definitiva.',
                $e->getMessage()
            );
        }

        $this->assertSame('PENDING', $own->fresh()->status);
    }

    /** @test */
    public function does_not_flag_pending_withholdings_from_another_month_and_keeps_the_original_message(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        $other   = $this->makeRefrend($user, ['period_month' => 2]);
        $this->makePendingLedger($user, $other);

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutPendingWithholdingsException.');
        } catch (CycleCutPendingWithholdingsException $e) {
            $this->assertFalse($e->payload()['withholdings'][0]['is_current_refrend']);
            $this->assertSame(
                'Tiene retenciones pendientes de pago; resuélvalas en Pago de meses retenidos antes de cerrar el ciclo.',
                $e->getMessage()
            );
        }
    }

    /** @test */
    public function cascade_closes_later_open_refrends_at_zero_and_locked(): void
    {
        $user         = $this->makeBecario();
        $refrend      = $this->makeRefrend($user, ['period_month' => 6]);
        $laterRefrend = $this->makeRefrend($user, [
            'period_month'    => 7,
            'workflow_status' => 'DRAFT',
            'base_amount'     => 1000.00,
            'final_amount'    => 1000.00,
        ]);

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        $laterRefrend->refresh();
        $this->assertSame('EGRESADO', $laterRefrend->resolution_type);
        $this->assertSame('0.00', $laterRefrend->final_amount);
        $this->assertSame('CLOSED', $laterRefrend->workflow_status);
        $this->assertTrue($laterRefrend->isLocked());
        $this->assertNotNull($laterRefrend->locked_at);
    }
}
