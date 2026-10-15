<?php

namespace Tests\Unit;

use App\Enums\CycleClosureReason;
use App\Enums\ScholarshipType;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Models\ScholarshipProfile;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresado-baja-definitiva, design D3, task 1.7/1.9. Every generation
 * path (bulk query filter, single-user, advance-for-future) must honor the
 * cycle_closed_period stop marker (design D1/D2) — a cut becario is an
 * EXPECTED skip, never an error.
 */
class GenerateMonthlyRefrendsServiceCycleClosedTest extends TestCase
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
            'reticula_start_date' => null,
            'reticula_end_date'   => null,
        ], $overrides));
    }

    // ── Single-user path: generateForUser() ──────────────────────────────

    /** @test */
    public function generate_for_user_throws_cycle_closed_exception_for_a_period_after_the_cut(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 15));
        $profile = $this->makeProfile([
            'cycle_closed_period'  => '2026-10-01',
            'cycle_closure_reason' => CycleClosureReason::EGRESADO->value,
        ]);

        $this->expectException(ScholarshipCycleClosedException::class);

        $this->service->generateForUser($profile, 2026, 11);
    }

    /** @test */
    public function generate_for_user_allows_the_exact_cut_period(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $profile = $this->makeProfile([
            'cycle_closed_period'  => '2026-10-01',
            'cycle_closure_reason' => CycleClosureReason::EGRESADO->value,
        ]);

        $refrend = $this->service->generateForUser($profile, 2026, 10);

        $this->assertNotNull($refrend);
    }

    /** @test */
    public function generate_for_user_is_unaffected_when_no_marker_is_set(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 15));
        $profile = $this->makeProfile();

        $refrend = $this->service->generateForUser($profile, 2026, 11);

        $this->assertNotNull($refrend);
    }

    // ── Advance path: generateFutureForAdvance() ─────────────────────────

    /** @test */
    public function advance_path_throws_cycle_closed_exception_for_a_period_after_the_cut(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $profile = $this->makeProfile([
            'cycle_closed_period'  => '2026-10-01',
            'cycle_closure_reason' => CycleClosureReason::BAJA_DEFINITIVA->value,
        ]);

        $this->expectException(ScholarshipCycleClosedException::class);

        $this->service->generateFutureForAdvance($profile, 2026, 11);
    }

    /** @test */
    public function advance_path_allows_a_future_period_at_or_before_the_cut(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $profile = $this->makeProfile([
            'cycle_closed_period'  => '2026-10-01',
            'cycle_closure_reason' => CycleClosureReason::BAJA_DEFINITIVA->value,
        ]);

        $refrend = $this->service->generateFutureForAdvance($profile, 2026, 10);

        $this->assertNotNull($refrend);
    }

    // ── Bulk path: generateForPeriod() ────────────────────────────────────

    /** @test */
    public function bulk_generation_silently_skips_a_cut_becario_without_counting_it_as_an_error(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 15));
        $this->makeProfile([
            'cycle_closed_period'  => '2026-10-01',
            'cycle_closure_reason' => CycleClosureReason::EGRESADO->value,
        ]);

        $stats = $this->service->generateForPeriod(2026, 11, 'MERIDA', null);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame([], $stats['failures']);
    }

    /** @test */
    public function bulk_generation_still_creates_the_exact_cut_period_for_a_cut_becario(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $this->makeProfile([
            'cycle_closed_period'  => '2026-10-01',
            'cycle_closure_reason' => CycleClosureReason::EGRESADO->value,
        ]);

        $stats = $this->service->generateForPeriod(2026, 10, 'MERIDA', null);

        $this->assertSame(1, $stats['created']);
    }

    /** @test */
    public function bulk_generation_creates_normally_for_a_becario_with_no_marker(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 15));
        $this->makeProfile();

        $stats = $this->service->generateForPeriod(2026, 11, 'MERIDA', null);

        $this->assertSame(1, $stats['created']);
    }
}
