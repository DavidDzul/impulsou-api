<?php

namespace Tests\Unit;

use App\Actions\Scholarship\CloseScholarshipCycleAction;
use App\Enums\RefrendType;
use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Enums\WithholdingCancellationReason;
use App\Exceptions\CycleCutActiveTelmexCoverageException;
use App\Exceptions\CycleCutBlockedException;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
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
 * cancellation of ANY pending withholding (sdd/retenciones-pendientes), and cascade-closing later open
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
    public function a_later_month_with_a_pending_advance_payment_no_longer_blocks_the_cut_it_gets_cancelled(): void
    {
        $user         = $this->makeBecario();
        $profile      = $this->makeProfile($user);
        $refrend      = $this->makeRefrend($user, ['period_month' => 6]);
        $laterRefrend = $this->makeRefrend($user, ['period_year' => 2027, 'period_month' => 2, 'workflow_status' => 'DRAFT', 'status' => RefrendStatus::DRAFT->value]);
        $month        = $this->makeAdvancePaymentMonth($user, $laterRefrend, 'PENDING');

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        $this->assertSame('CANCELLED', $month->fresh()->status);
        $this->assertSame('CANCELLED', $month->advancePayment->fresh()->status);
        $this->assertSame('Cancelado por EGRESO del 06/2026', $laterRefrend->fresh()->resolution_notes);
        $this->assertSame(RefrendStatus::CANCELLED, $laterRefrend->fresh()->status);
        $this->assertSame('2026-06-01', $profile->fresh()->cycle_closed_period->toDateString());
        $this->assertNull($refrend->fresh()->resolution_notes);
    }

    /** @test */
    public function the_cascade_tags_a_later_refrends_origin_ledger_row_as_cycle_cut(): void
    {
        $user         = $this->makeBecario();
        $refrend      = $this->makeRefrend($user, ['period_month' => 6]);
        $laterRefrend = $this->makeRefrend($user, [
            'period_month'    => 7,
            'workflow_status' => 'DRAFT',
            'status'          => RefrendStatus::DRAFT->value,
        ]);
        // A zero-amount PENDING row is not "pending with balance" (the
        // canceller's pending() scope does not see it) yet is still reached by
        // the cascade cancel.
        $ledger = ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $laterRefrend->id,
            'period_year'       => 2026,
            'period_month'      => 7,
            'withheld_amount'   => '0.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        $ledger = $ledger->fresh();
        $this->assertSame('CANCELLED', $ledger->status);
        $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $ledger->cancellation_reason);
        $this->assertNotNull($ledger->cancelled_at);
        $this->assertSame($user->id, $ledger->cancelled_by_id);
    }

    /** @test */
    public function a_later_advance_refrend_that_is_paid_still_blocks_with_the_paid_reason_and_cancels_nothing(): void
    {
        $user         = $this->makeBecario();
        $refrend      = $this->makeRefrend($user, ['period_month' => 6]);
        $laterRefrend = $this->makeRefrend($user, ['period_year' => 2027, 'period_month' => 2]); // CLOSED by default
        $month        = $this->makeAdvancePaymentMonth($user, $laterRefrend, 'PENDING');

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutBlockedException.');
        } catch (CycleCutBlockedException $e) {
            $this->assertSame(
                [['year' => 2027, 'month' => 2, 'reason' => 'PAID']],
                $e->payload()['blocking_months']
            );
        }

        $this->assertSame('PENDING', $month->fresh()->status);
        $this->assertNull(ScholarshipProfile::where('user_id', $user->id)->first());
    }

    /** @test */
    public function rejects_the_cut_when_a_later_refrend_is_already_paid_or_locked(): void
    {
        $user         = $this->makeBecario();
        $refrend      = $this->makeRefrend($user, ['period_month' => 6]);
        $laterRefrend = $this->makeRefrend($user, [
            'period_month'    => 7,
            'status'          => RefrendStatus::PAID->value,
            'workflow_status' => 'CLOSED',
            'locked_at'       => now(),
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

        $this->assertSame(RefrendStatus::PAID, $laterRefrend->fresh()->status);
    }

    /** @test */
    public function rejects_the_cut_when_a_later_refrend_was_paid_by_cheque(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        $this->makeRefrend($user, [
            'period_month'        => 7,
            'status'              => RefrendStatus::PAID->value,
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now(),
            'payment_batch_id'    => null,
            'paid_payment_method' => 'CHEQUE',
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

    private function makePendingLedger(User $user, ScholarshipRefrend $origin, string $withheld = '300.00', string $paid = '0.00'): ScholarshipWithholding
    {
        return ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $origin->id,
            'period_year'       => $origin->period_year,
            'period_month'      => $origin->period_month,
            'withheld_amount'   => $withheld,
            'paid_amount'       => $paid,
            'status'            => 'PENDING',
        ]);
    }

    /** @test */
    public function pending_withholdings_no_longer_block_the_cut_they_are_cancelled_as_cycle_cut(): void
    {
        $user      = $this->makeBecario();
        $this->makeProfile($user);
        $refrend   = $this->makeRefrend($user, ['period_month' => 6]);
        $oldOrigin = $this->makeRefrend($user, ['period_year' => 2020, 'period_month' => 1]);
        $otherOrigin = $this->makeRefrend($user, ['period_month' => 2]);
        $old   = $this->makePendingLedger($user, $oldOrigin, '150.00');
        $other = $this->makePendingLedger($user, $otherOrigin, '300.00');

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        foreach ([$old, $other] as $ledger) {
            $ledger = $ledger->fresh();
            $this->assertSame('CANCELLED', $ledger->status);
            $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $ledger->cancellation_reason);
            $this->assertNotNull($ledger->cancelled_at);
            $this->assertSame($user->id, $ledger->cancelled_by_id);
            $this->assertSame(
                1,
                ScholarshipRefrendLog::where('action', 'WITHHOLDING_CANCELLED')
                    ->where('scholarship_refrend_id', $ledger->origin_refrend_id)
                    ->count()
            );
        }
        $this->assertNotNull(ScholarshipProfile::where('user_id', $user->id)->first()->cycle_closed_period);
    }

    /** @test */
    public function a_partially_paid_withholding_is_cancelled_with_its_paid_amount_frozen(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        $other   = $this->makeRefrend($user, ['period_month' => 2]);
        $partial = $this->makePendingLedger($user, $other, '300.00', '100.00');

        $this->action->execute($refrend, 'BAJA_DEFINITIVA', $user->id);

        $partial = $partial->fresh();
        $this->assertSame('CANCELLED', $partial->status);
        $this->assertSame('100.00', $partial->paid_amount);
        $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $partial->cancellation_reason);
    }

    /** @test */
    public function the_cut_months_own_pending_withholding_is_cancelled_as_cycle_cut(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        $own     = $this->makePendingLedger($user, $refrend);

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        $own = $own->fresh();
        $this->assertSame('CANCELLED', $own->status);
        $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $own->cancellation_reason);
    }

    /** @test */
    public function the_cascade_leaves_a_later_refrends_real_pending_withholding_to_the_canceller(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        $later   = $this->makeRefrend($user, [
            'period_month'    => 7,
            'workflow_status' => 'DRAFT',
            'status'          => RefrendStatus::DRAFT->value,
        ]);
        $ledger = $this->makePendingLedger($user, $later, '250.00');

        $this->action->execute($refrend, 'EGRESADO', $user->id);

        $ledger = $ledger->fresh();
        $this->assertSame('CANCELLED', $ledger->status);
        $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $ledger->cancellation_reason);
        $this->assertSame(
            1,
            ScholarshipRefrendLog::where('action', 'WITHHOLDING_CANCELLED')->where('scholarship_refrend_id', $later->id)->count()
        );
        $this->assertSame(RefrendStatus::CANCELLED, $later->fresh()->status);
    }

    /** @test */
    public function a_blocked_cut_cancels_no_withholding(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user, ['period_month' => 6]);
        $other   = $this->makeRefrend($user, ['period_month' => 2]);
        $ledger  = $this->makePendingLedger($user, $other);
        TelmexCoverage::create([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation' => 'TELMEX',
            'start_period'                   => '2026-01-01',
            'status'                         => 'ACTIVA',
        ]);

        try {
            $this->action->execute($refrend, 'EGRESADO', $user->id);
            $this->fail('Expected CycleCutActiveTelmexCoverageException.');
        } catch (CycleCutActiveTelmexCoverageException $e) {
            // expected
        }

        $this->assertSame('PENDING', $ledger->fresh()->status);
        $this->assertNull($ledger->fresh()->cancelled_at);
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
