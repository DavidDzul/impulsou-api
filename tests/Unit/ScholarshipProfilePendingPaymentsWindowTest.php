<?php

namespace Tests\Unit;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/generaciones-activas-refrendos, R1: ScholarshipProfile::
 * scopePendingPaymentsWindow() is the ONE definition of "becario with
 * pending payments" (user active, open for the current period, reticula end
 * present, egreso administrativo month >= current month).
 */
class ScholarshipProfilePendingPaymentsWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProfile(array $profile = [], array $user = []): ScholarshipProfile
    {
        $u = User::factory()->create(array_merge(['user_type' => 'BEC_ACTIVE', 'active' => true], $user));

        return ScholarshipProfile::factory()->create(array_merge(['user_id' => $u->id], $profile));
    }

    private function pendingIds(?Carbon $now = null): array
    {
        return ScholarshipProfile::query()->pendingPaymentsWindow($now)->pluck('scholarship_profiles.id')->all();
    }

    /** @test */
    public function counts_a_profile_whose_egreso_month_is_the_current_month(): void
    {
        // S1.1: end Aug -> egreso end of Oct; now Oct.
        Carbon::setTestNow('2026-10-15');
        $p = $this->makeProfile(['reticula_end_date' => '2026-08-20']);

        $this->assertSame([$p->id], $this->pendingIds());
    }

    /** @test */
    public function excludes_a_profile_once_the_egreso_month_has_passed(): void
    {
        // S1.2: end Aug, now Nov -> egreso (Oct) is over.
        Carbon::setTestNow('2026-11-02');
        $this->makeProfile(['reticula_end_date' => '2026-08-20']);
        $inWindow = $this->makeProfile(['reticula_end_date' => '2026-09-01']);

        $this->assertSame([$inWindow->id], $this->pendingIds());
    }

    /** @test */
    public function boundary_first_day_of_month_minus_two_is_in_and_the_day_before_is_out(): void
    {
        Carbon::setTestNow('2026-10-31 23:00:00');
        $in  = $this->makeProfile(['reticula_end_date' => '2026-08-01']);
        $out = $this->makeProfile(['reticula_end_date' => '2026-07-31']);

        $ids = $this->pendingIds();

        $this->assertContains($in->id, $ids);
        $this->assertNotContains($out->id, $ids);
    }

    /** @test */
    public function excludes_inactive_users(): void
    {
        // S1.3
        Carbon::setTestNow('2026-10-15');
        $this->makeProfile(['reticula_end_date' => '2026-12-31'], ['active' => false]);
        $active = $this->makeProfile(['reticula_end_date' => '2026-12-31']);

        $this->assertSame([$active->id], $this->pendingIds());
    }

    /** @test */
    public function excludes_profiles_cut_before_the_current_month_but_keeps_cuts_in_it(): void
    {
        // S1.4
        Carbon::setTestNow('2026-10-15');
        $this->makeProfile(['reticula_end_date' => '2026-12-31', 'cycle_closed_period' => '2026-09-01']);
        $cutNow = $this->makeProfile(['reticula_end_date' => '2026-12-31', 'cycle_closed_period' => '2026-10-01']);

        $this->assertSame([$cutNow->id], $this->pendingIds());
    }

    /** @test */
    public function excludes_profiles_without_reticula_end_date(): void
    {
        // S1.5
        Carbon::setTestNow('2026-10-15');
        $this->makeProfile(['reticula_start_date' => null, 'reticula_end_date' => null]);
        $dated = $this->makeProfile(['reticula_end_date' => '2026-12-31']);

        $this->assertSame([$dated->id], $this->pendingIds());
    }

    /** @test */
    public function counts_profiles_with_unconfigured_amounts(): void
    {
        // S1.6
        Carbon::setTestNow('2026-10-15');
        $p = $this->makeProfile(['reticula_end_date' => '2026-12-31', 'config_set_at' => null]);

        $this->assertSame([$p->id], $this->pendingIds());
    }

    /** @test */
    public function ignores_user_type(): void
    {
        // S1.7
        Carbon::setTestNow('2026-10-15');
        $a = $this->makeProfile(['reticula_end_date' => '2026-12-31'], ['user_type' => 'BEC_INACTIVE']);
        $b = $this->makeProfile(['reticula_end_date' => '2026-12-31'], ['user_type' => 'BEC_ACTIVE']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->pendingIds());
    }

    /** @test */
    public function an_explicit_now_overrides_the_clock(): void
    {
        Carbon::setTestNow('2026-10-15');
        $p = $this->makeProfile(['reticula_end_date' => '2026-08-20']);

        $this->assertSame([$p->id], $this->pendingIds(Carbon::parse('2026-10-01')));
        $this->assertSame([], $this->pendingIds(Carbon::parse('2026-11-01')));
    }

    /** @test */
    public function matches_the_egreso_administrativo_date_predicate_over_an_end_date_matrix(): void
    {
        // S1.8 equivalence with the model's own formula.
        $now = Carbon::parse('2026-10-15');
        Carbon::setTestNow($now);

        $profiles = [];
        foreach (['2026-05-31', '2026-06-30', '2026-07-31', '2026-08-01', '2026-08-31',
                  '2026-09-15', '2026-10-31', '2027-01-31', '2025-12-31', '2026-02-28'] as $end) {
            $profiles[] = $this->makeProfile(['reticula_end_date' => $end]);
        }

        $expected = collect($profiles)
            ->filter(fn (ScholarshipProfile $p) => $p->egresoAdministrativoDate()->gte($now->copy()->startOfMonth()))
            ->pluck('id')
            ->all();

        $this->assertNotEmpty($expected);
        $this->assertLessThan(count($profiles), count($expected));
        $this->assertEqualsCanonicalizing($expected, $this->pendingIds());
    }
}
