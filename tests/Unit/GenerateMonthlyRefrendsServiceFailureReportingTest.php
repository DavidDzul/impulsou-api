<?php

namespace Tests\Unit;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/verificacion-incidencias-ux, PR1, tasks 1.4/1.5 — RED-first, written
 * against the unchanged generateForPeriod() loop (plain created/skipped/
 * errors counters, no failures[]). Drives the new classification: a
 * ReticulaPeriodOutOfRangeException is counted as `skipped_out_of_reticula`
 * (NOT `errors`), any other \Throwable is counted as `errors`, and both add
 * a `failures[]` entry with user_id/name/reason/kind. Successful or
 * already-exists outcomes add no failures[] entry.
 */
class GenerateMonthlyRefrendsServiceFailureReportingTest extends TestCase
{
    use RefreshDatabase;

    private GenerateMonthlyRefrendsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(GenerateMonthlyRefrendsService::class);
    }

    private function makeProfile(array $userOverrides = [], array $profileOverrides = []): ScholarshipProfile
    {
        $user = User::factory()->create(array_merge([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => 'MERIDA',
            'active'     => true,
            'first_name' => 'Juan',
            'last_name'  => 'López',
        ], $userOverrides));

        return ScholarshipProfile::create(array_merge([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => now()->toDateString(),
            'config_set_at'        => now(),
        ], $profileOverrides));
    }

    /** @test */
    public function out_of_reticula_becario_is_counted_as_skipped_out_of_reticula_not_error(): void
    {
        // reticula_start_date in the future relative to "now" -> the current
        // period is BEFORE the reticula window starts.
        $profile = $this->makeProfile([], [
            'reticula_start_date' => now()->addMonths(2)->startOfMonth()->toDateString(),
        ]);

        $stats = $this->service->generateForPeriod(now()->year, now()->month, 'MERIDA', null);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame(1, $stats['skipped_out_of_reticula']);
        $this->assertSame(0, $stats['skipped']);

        $this->assertCount(1, $stats['failures']);
        $failure = $stats['failures'][0];
        $this->assertSame($profile->user_id, $failure['user_id']);
        $this->assertSame('Juan López', $failure['name']);
        $this->assertSame('skipped_out_of_reticula', $failure['kind']);
        $this->assertStringContainsString('retícula', $failure['reason']);
    }

    /** @test */
    public function a_future_period_is_counted_as_error_with_a_failure_entry(): void
    {
        // assertPeriodNotInFuture() throws a plain \DomainException (not the
        // reticula one) — must stay classified as `errors`, matching the
        // pre-existing FuturePeriodTest expectation of errors=1.
        $profile = $this->makeProfile();

        $nextMonth = now()->addMonthNoOverflow();

        $stats = $this->service->generateForPeriod($nextMonth->year, $nextMonth->month, 'MERIDA', null);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['skipped_out_of_reticula']);
        $this->assertSame(1, $stats['errors']);

        $this->assertCount(1, $stats['failures']);
        $failure = $stats['failures'][0];
        $this->assertSame($profile->user_id, $failure['user_id']);
        $this->assertSame('Juan López', $failure['name']);
        $this->assertSame('error', $failure['kind']);
        $this->assertNotEmpty($failure['reason']);
    }

    /** @test */
    public function a_successful_generation_adds_no_failure_entry(): void
    {
        $this->makeProfile();

        $stats = $this->service->generateForPeriod(now()->year, now()->month, 'MERIDA', null);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['skipped_out_of_reticula']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame(0, $stats['skipped_config_pending']);
        $this->assertSame(0, $stats['skipped_reticula_missing']);
        $this->assertCount(0, $stats['failures']);
    }

    /** @test */
    public function an_already_existing_refrend_is_counted_as_skipped_with_no_failure_entry(): void
    {
        $profile = $this->makeProfile();

        ScholarshipRefrend::create([
            'user_id'       => $profile->user_id,
            'period_year'   => now()->year,
            'period_month'  => now()->month,
            'refrend_type'  => RefrendType::NORMAL->value,
            'base_amount'   => 2000.00,
            'final_amount'  => 2000.00,
            'snapshot_name' => 'Juan López',
        ]);

        $stats = $this->service->generateForPeriod(now()->year, now()->month, 'MERIDA', null);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['skipped_out_of_reticula']);
        $this->assertSame(0, $stats['errors']);
        $this->assertCount(0, $stats['failures']);
    }
}
