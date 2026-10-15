<?php

namespace Tests\Unit;

use App\Enums\CycleClosureReason;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresado-baja-definitiva, design D1/D2, task 1.5/1.8.
 *
 * isCycleClosedFor(): pure model predicate, no DB — same pattern as
 * ScholarshipProfileEgresoCutoffTest. A period is closed only when it is
 * STRICTLY AFTER cycle_closed_period; the cut period itself (period == cut)
 * is explicitly allowed, matching design D2's "period > closed" wording.
 *
 * scopeOpenForPeriod(): a real Eloquent query scope, needs persisted rows.
 */
class ScholarshipProfileCycleClosureTest extends TestCase
{
    use RefreshDatabase;

    private function profile(?string $cycleClosedPeriod): ScholarshipProfile
    {
        $profile = new ScholarshipProfile();
        $profile->cycle_closed_period = $cycleClosedPeriod;

        return $profile;
    }

    /** @test */
    public function is_cycle_closed_for_is_false_when_the_marker_is_not_set(): void
    {
        $profile = $this->profile(null);

        $this->assertFalse($profile->isCycleClosedFor(2026, 11));
    }

    /** @test */
    public function is_cycle_closed_for_is_false_for_the_exact_cut_period(): void
    {
        $profile = $this->profile('2026-10-01');

        $this->assertFalse($profile->isCycleClosedFor(2026, 10));
    }

    /** @test */
    public function is_cycle_closed_for_is_true_for_a_period_strictly_after_the_cut(): void
    {
        $profile = $this->profile('2026-10-01');

        $this->assertTrue($profile->isCycleClosedFor(2026, 11));
    }

    /** @test */
    public function is_cycle_closed_for_is_false_for_a_period_before_the_cut(): void
    {
        $profile = $this->profile('2026-10-01');

        $this->assertFalse($profile->isCycleClosedFor(2026, 9));
    }

    private function makeProfile(array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipProfile::create(array_merge([
            'user_id'          => $user->id,
            'scholarship_type' => ScholarshipType::IU->value,
            'monthly_amount'   => 2000.00,
            'monto_apoyo'      => 0,
        ], $overrides));
    }

    /** @test */
    public function scope_open_for_period_includes_a_profile_with_no_marker(): void
    {
        $profile = $this->makeProfile();

        $ids = ScholarshipProfile::openForPeriod(2026, 11)->pluck('id');

        $this->assertTrue($ids->contains($profile->id));
    }

    /** @test */
    public function scope_open_for_period_includes_the_exact_cut_period(): void
    {
        $profile = $this->makeProfile(['cycle_closed_period' => '2026-10-01']);

        $ids = ScholarshipProfile::openForPeriod(2026, 10)->pluck('id');

        $this->assertTrue($ids->contains($profile->id));
    }

    /** @test */
    public function scope_open_for_period_excludes_a_profile_closed_before_the_period(): void
    {
        $profile = $this->makeProfile(['cycle_closed_period' => '2026-10-01']);

        $ids = ScholarshipProfile::openForPeriod(2026, 11)->pluck('id');

        $this->assertFalse($ids->contains($profile->id));
    }

    /** @test */
    public function scope_open_for_period_includes_a_profile_closed_after_the_period(): void
    {
        $profile = $this->makeProfile(['cycle_closed_period' => '2026-12-01']);

        $ids = ScholarshipProfile::openForPeriod(2026, 10)->pluck('id');

        $this->assertTrue($ids->contains($profile->id));
    }

    /** @test */
    public function cycle_closure_reason_cast_round_trips_through_the_enum(): void
    {
        $profile = $this->makeProfile([
            'cycle_closed_period'  => '2026-10-01',
            'cycle_closure_reason' => CycleClosureReason::EGRESADO->value,
        ]);

        $this->assertSame(CycleClosureReason::EGRESADO, $profile->fresh()->cycle_closure_reason);
    }
}
