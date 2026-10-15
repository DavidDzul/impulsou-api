<?php

namespace Tests\Unit;

use App\Actions\Scholarship\CloseScholarshipCycleAction;
use App\Enums\RefrendType;
use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresado-baja-definitiva, design D4/D10, task 2a.1/2a.11 (PR2a minimal
 * scope): marker set, CYCLE_ALREADY_CLOSED guard, GraduateBecarioAction
 * (EGRESADO) / active=false (BAJA_DEFINITIVA). The blocking-months/pending-
 * withholdings/Telmex-coverage guards belong to PR2b and are NOT exercised
 * here.
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
}
