<?php

namespace Tests\Unit;

use App\Actions\Scholarship\CloseScholarshipCycleAction;
use App\Enums\CycleClosureReason;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresar-boton-corte-ciclo, REQ-CC-1/REQ-CC-2 (S13-S19). A manual cut
 * (cycle_closed_period = M) has priority over the retícula dates: after the
 * cut, NO refrend of any kind (normal or EGRESO_RETICULA) is generated for a
 * month > M by any path, even when the retícula ends after M. Regression
 * characterization of existing behavior; both retícula dates are set.
 *
 * Fixture: retícula 2026-01-01..2026-12-31 (automatic egreso month 2027-02),
 * cut at 2026-06, "now" 2027-03-15 so every tested period is already past.
 */
class GenerateMonthlyRefrendsServiceManualCutPriorityTest extends TestCase
{
    use RefreshDatabase;

    private GenerateMonthlyRefrendsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(GenerateMonthlyRefrendsService::class);
        Carbon::setTestNow(Carbon::create(2027, 3, 15));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProfile(array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipProfile::create(array_merge([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2026-01-01',
            'reticula_end_date'   => '2026-12-31',
            'config_set_at'       => now(),
        ], $overrides));
    }

    private function makeCutProfile(array $overrides = []): ScholarshipProfile
    {
        return $this->makeProfile(array_merge([
            'cycle_closed_period'  => '2026-06-01',
            'cycle_closure_reason' => CycleClosureReason::EGRESADO->value,
        ], $overrides));
    }

    private function refrendsFor(ScholarshipProfile $profile)
    {
        return ScholarshipRefrend::where('user_id', $profile->user_id);
    }

    /** @test */
    public function bulk_generation_creates_nothing_after_the_cut_including_the_reticula_egreso_month(): void
    {
        $cut   = $this->makeCutProfile();
        $uncut = $this->makeProfile();

        // M+1, M+2 and the automatic egreso month (reticula end + 2).
        foreach ([[2026, 7], [2026, 8], [2027, 2]] as [$year, $month]) {
            $this->service->generateForPeriod($year, $month, 'MERIDA', null);
        }

        $this->assertSame(0, $this->refrendsFor($cut)->count());
        $this->assertGreaterThan(0, $this->refrendsFor($uncut)->count(), 'Other users must be unaffected.');
    }

    /** @test */
    public function bulk_generation_does_not_count_the_cut_user_as_created_or_error(): void
    {
        $this->makeCutProfile();

        $stats = $this->service->generateForPeriod(2026, 7, 'MERIDA', null);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame([], $stats['failures']);
    }

    /** @test */
    public function generate_for_user_rejects_any_period_after_the_cut_including_the_egreso_month(): void
    {
        $profile = $this->makeCutProfile();

        foreach ([[2026, 7], [2026, 8], [2027, 2]] as [$year, $month]) {
            try {
                $this->service->generateForUser($profile, $year, $month);
                $this->fail("Expected ScholarshipCycleClosedException for {$month}/{$year}.");
            } catch (ScholarshipCycleClosedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->refrendsFor($profile)->count());
    }

    /** @test */
    public function generate_future_for_advance_rejects_months_beyond_the_cut(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 15));
        $profile = $this->makeCutProfile();

        foreach ([[2026, 7], [2026, 8], [2027, 2]] as [$year, $month]) {
            try {
                $this->service->generateFutureForAdvance($profile, $year, $month);
                $this->fail("Expected ScholarshipCycleClosedException for {$month}/{$year}.");
            } catch (ScholarshipCycleClosedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->refrendsFor($profile)->count());
    }

    /** @test */
    public function no_egreso_reticula_refrend_exists_for_a_cut_user_in_the_egreso_month(): void
    {
        $cut = $this->makeCutProfile();

        $this->service->generateForPeriod(2027, 2, 'MERIDA', null);

        $this->assertSame(0, $this->refrendsFor($cut)
            ->where('resolution_type', ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA)
            ->count());
        $this->assertSame(0, $this->refrendsFor($cut)->count());
    }

    /** @test */
    public function the_cut_month_refrend_is_preserved_and_not_duplicated(): void
    {
        $profile = $this->makeCutProfile();

        $first  = $this->service->generateForUser($profile, 2026, 6);
        $second = $this->service->generateForUser($profile, 2026, 6);
        $stats  = $this->service->generateForPeriod(2026, 6, 'MERIDA', null);

        $this->assertNotNull($first);
        $this->assertNull($second, 'exists()-skip must return null for the cut month.');
        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $this->refrendsFor($profile)
            ->where('period_year', 2026)->where('period_month', 6)
            ->where('refrend_type', RefrendType::NORMAL->value)->count());
    }

    /** @test */
    public function control_an_uncut_user_still_gets_the_egreso_reticula_refrend_in_the_egreso_month(): void
    {
        $uncut = $this->makeProfile();

        $this->service->generateForPeriod(2027, 2, 'MERIDA', null);

        $this->assertSame(1, $this->refrendsFor($uncut)
            ->where('period_year', 2027)->where('period_month', 2)
            ->where('resolution_type', ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA)
            ->count());
    }

    /** @test */
    public function close_cycle_action_still_cascade_closes_a_later_existing_month_and_sets_the_marker(): void
    {
        $profile = $this->makeProfile();
        Carbon::setTestNow(Carbon::create(2026, 7, 15));
        $cutRefrend   = $this->service->generateForUser($profile, 2026, 6);
        $laterRefrend = $this->service->generateForUser($profile, 2026, 7);

        $this->app->make(CloseScholarshipCycleAction::class)
            ->execute($cutRefrend, CycleClosureReason::EGRESADO->value, $profile->user_id);

        $profile->refresh();
        $laterRefrend->refresh();
        $this->assertSame('2026-06-01', $profile->cycle_closed_period->toDateString());
        $this->assertSame('0.00', $laterRefrend->final_amount);
        $this->assertTrue($laterRefrend->isLocked());
        $this->assertNull($this->service->generateForUser($profile, 2026, 6), 'cut month still exists-skipped');
    }
}
