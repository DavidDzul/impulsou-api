<?php

namespace Tests\Unit;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
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

    // ── PR1: assertPeriodWithinReticula() splits into assertPeriodNotInFuture()
    // (normal path, restored/committed), assertPeriodIsFuture() (new, inverse,
    // advance-only path), and assertPeriodWithinReticula() keeping only the
    // reticula-range checks. generateFutureForAdvance() is the new, separately
    // named, explicitly-future-only entry point. ──

    /** @test */
    public function normal_generation_rejects_a_future_period(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $profile = $this->makeProfile();

        try {
            $this->service->generateForUser($profile, 2026, 10);
            $this->fail('Expected a DomainException for a future period.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('10', $e->getMessage());
        }

        $this->assertTrue(
            ScholarshipRefrend::where('user_id', $profile->user_id)
                ->where('period_year', 2026)
                ->where('period_month', 10)
                ->doesntExist(),
            'No refrend row should be left behind by a rejected future-period generation.'
        );
    }

    /** @test */
    public function normal_generation_accepts_the_current_period(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $profile = $this->makeProfile();

        $refrend = $this->service->generateForUser($profile, 2026, 9);

        $this->assertNotNull($refrend);
        $this->assertSame(RefrendType::NORMAL, $refrend->refrend_type);
    }

    /** @test */
    public function bulk_generation_counts_a_future_period_as_error_not_created(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $this->makeProfile(['campus' => 'MERIDA']);

        $stats = $this->service->generateForPeriod(2026, 10, 'MERIDA', null);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['errors']);
    }

    /** @test */
    public function advance_path_creates_a_future_refrend_in_draft(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $profile = $this->makeProfile();

        $refrend = $this->service->generateFutureForAdvance($profile, 2027, 6);

        $this->assertInstanceOf(ScholarshipRefrend::class, $refrend);
        $this->assertSame(RefrendType::NORMAL, $refrend->refrend_type);
        $this->assertSame('DRAFT', $refrend->workflow_status);
        $this->assertNull($refrend->resolution_type);
        $this->assertSame(2027, $refrend->period_year);
        $this->assertSame(6, $refrend->period_month);
    }

    /** @test */
    public function advance_path_still_rejects_periods_outside_the_reticula(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $profile = $this->makeProfile([
            'reticula_end_date' => Carbon::create(2026, 10, 1)->toDateString(),
        ]);

        // Egreso administrativo = reticula_end_date + 2 months = 2026-12-01.
        // 2027-06 is well past that window, so even though it is a valid
        // future period, it must still be rejected by the reticula check.
        $this->expectException(\DomainException::class);

        $this->service->generateFutureForAdvance($profile, 2027, 6);
    }

    /** @test */
    public function advance_path_rejects_a_non_future_period(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $profile = $this->makeProfile();

        $this->expectException(\DomainException::class);

        $this->service->generateFutureForAdvance($profile, 2026, 9);
    }
}
