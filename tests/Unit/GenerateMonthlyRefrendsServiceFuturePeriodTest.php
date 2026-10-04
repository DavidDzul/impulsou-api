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

    // ── sdd/egresado-status-timing, design D2 (tasks 4.2, 4.3) ──────────────
    // reticula_end_date = 2026-07-31 -> cutoff (egreso administrativo) =
    // 2026-09-30. Month+1 = August 2026 (normal), month+2 = September 2026
    // (auto $0 egreso), month+3 = October 2026 (still blocked).

    /** @test */
    public function month_plus_one_generates_a_normal_full_pay_refrend(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $profile = $this->makeProfile(['reticula_end_date' => '2026-07-31']);

        $refrend = $this->service->generateForUser($profile, 2026, 8);

        $this->assertNull($refrend->resolution_type);
        $this->assertNotSame('CLOSED', $refrend->workflow_status);
        $this->assertNull($refrend->locked_at);
        $this->assertSame('2000.00', (string) $refrend->final_amount);
    }

    /** @test */
    public function month_plus_two_auto_generates_a_zeroed_closed_egreso_refrend(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $profile = $this->makeProfile(['reticula_end_date' => '2026-07-31']);

        $refrend = $this->service->generateForUser($profile, 2026, 9);

        $this->assertSame('0.00', (string) $refrend->final_amount);
        $this->assertSame('0.00', (string) $refrend->amount_pending_from_previous);
        $this->assertSame('0.00', (string) $refrend->refund_amount_from_previous);
        $this->assertSame('0.00', (string) $refrend->advance_payment_amount);
        $this->assertSame(\App\Models\ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA, $refrend->resolution_type);
        $this->assertSame('CLOSED', $refrend->workflow_status);
        $this->assertNotNull($refrend->locked_at);
        $this->assertNull($refrend->locked_by_id);
        $this->assertSame('0.00', $refrend->total_to_pay);
    }

    /** @test */
    public function month_plus_two_flips_user_type_and_writes_a_graduated_log_with_null_performed_by_id(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $profile = $this->makeProfile(['reticula_end_date' => '2026-07-31']);

        $refrend = $this->service->generateForUser($profile, 2026, 9);

        $this->assertSame('BEC_INACTIVE', $profile->user->fresh()->user_type);

        $log = \App\Models\ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', 'graduated')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->performed_by_id);
        $this->assertStringContainsString('Egreso automático', $log->notes);
    }

    /** @test */
    public function month_plus_two_does_not_carry_a_discount_incident_even_when_the_profile_has_an_active_discount(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $profile = $this->makeProfile([
            'reticula_end_date'           => '2026-07-31',
            'active_discount_percentage'  => 50,
            'discount_valid_from'         => '2026-01-01',
            'discount_valid_until'        => '2027-01-01',
        ]);

        $refrend = $this->service->generateForUser($profile, 2026, 9);

        $this->assertSame(0, $refrend->incidents()->count());
    }

    /** @test */
    public function month_plus_three_still_throws_a_domain_exception(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $profile = $this->makeProfile(['reticula_end_date' => '2026-07-31']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('egreso administrativo');

        $this->service->generateForUser($profile, 2026, 10);
    }

    /** @test */
    public function month_plus_two_zeroing_does_not_touch_a_pending_withholding_ledger_row(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $profile = $this->makeProfile(['reticula_end_date' => '2026-07-31']);

        $originRefrend = ScholarshipRefrend::create([
            'user_id'       => $profile->user_id,
            'period_year'   => 2026,
            'period_month'  => 6,
            'refrend_type'  => RefrendType::NORMAL->value,
            'base_amount'   => 1000,
            'final_amount'  => 500,
            'snapshot_name' => 'Test Becario',
        ]);

        $withholding = \App\Models\ScholarshipWithholding::create([
            'user_id'           => $profile->user_id,
            'origin_refrend_id' => $originRefrend->id,
            'period_year'       => 2026,
            'period_month'      => 6,
            'withheld_amount'   => 500.00,
            'paid_amount'       => 0,
            'status'            => 'PENDING',
        ]);

        $refrend = $this->service->generateForUser($profile, 2026, 9);

        $this->assertSame('0.00', (string) $refrend->amount_pending_from_previous);
        $this->assertSame('0.00', $refrend->total_to_pay);

        $withholding->refresh();
        $this->assertSame('500.00', (string) $withholding->withheld_amount);
        $this->assertSame('0.00', (string) $withholding->paid_amount);
        $this->assertSame('PENDING', $withholding->status);
    }

    /** @test */
    public function advance_path_zeroes_the_money_for_an_egreso_period_but_does_not_flip_user_type(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15));
        $profile = $this->makeProfile(['reticula_end_date' => '2026-07-31']);

        // September 2026 is both (a) strictly future relative to "now"
        // (2026-08-15) — required by generateFutureForAdvance()'s own
        // assertPeriodIsFuture — and (b) the retícula's month+2 egreso
        // period, so both guards exercise simultaneously.
        $refrend = $this->service->generateFutureForAdvance($profile, 2026, 9);

        $this->assertSame('0.00', (string) $refrend->final_amount);
        $this->assertSame(\App\Models\ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA, $refrend->resolution_type);
        $this->assertSame('CLOSED', $refrend->workflow_status);

        // R3 — the advance path shares createRefrendForPeriod() but must
        // NEVER flip user_type for a FUTURE month.
        $this->assertSame('BEC_ACTIVE', $profile->user->fresh()->user_type);
        $this->assertNull(
            \App\Models\ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
                ->where('action', 'graduated')->first(),
            'The advance-payment path must never write a graduated log (R3 gating).'
        );
    }
}
