<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New business rule: a refrend cannot be generated for a period (year/month)
 * that has not started yet, according to the server's current date. This is
 * independent of (and in addition to) the existing reticula range check.
 */
class GenerateMonthlyRefrendsServiceFuturePeriodTest extends TestCase
{
    use RefreshDatabase;

    private GenerateMonthlyRefrendsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(GenerateMonthlyRefrendsService::class);
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
            // Wide open reticula so only the new future-period guard is
            // exercised, not the pre-existing reticula range check.
            'reticula_start_date' => null,
            'reticula_end_date'   => null,
            'payment_start_date'  => now()->toDateString(),
        ], $overrides));
    }

    /**
     * Invokes the private assertPeriodWithinReticula() guard directly via
     * reflection. This isolates the pure guard logic from
     * getAttendanceSummaryForPeriod(), which uses MySQL-only
     * DB::raw('CURDATE()') and errors under the SQLite test connection
     * (pre-existing, out-of-scope issue — see apply-progress).
     */
    private function assertPeriodWithinReticula(ScholarshipProfile $profile, int $year, int $month): void
    {
        $method = new \ReflectionMethod($this->service, 'assertPeriodWithinReticula');
        $method->setAccessible(true);
        $method->invoke($this->service, $profile, $year, $month);
    }

    /** @test */
    public function generate_for_user_throws_domain_exception_for_a_period_that_has_not_started_yet(): void
    {
        $profile = $this->makeProfile();

        $nextMonth = Carbon::now()->addMonthNoOverflow()->startOfMonth();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage((string) $nextMonth->month);

        // Exception is thrown by assertPeriodWithinReticula() BEFORE any
        // attendance query runs, so this is safe to call end-to-end.
        $this->service->generateForUser($profile, $nextMonth->year, $nextMonth->month);
    }

    /** @test */
    public function assert_period_within_reticula_allows_the_current_month(): void
    {
        $profile = $this->makeProfile();
        $now     = Carbon::now();

        $this->assertPeriodWithinReticula($profile, $now->year, $now->month);

        // No exception thrown — reaching this line is the assertion.
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function assert_period_within_reticula_allows_a_past_month_within_reticula(): void
    {
        $profile = $this->makeProfile();
        $past    = Carbon::now()->subMonths(2);

        $this->assertPeriodWithinReticula($profile, $past->year, $past->month);

        $this->addToAssertionCount(1);
    }
}
