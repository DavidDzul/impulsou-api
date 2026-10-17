<?php

namespace Tests\Unit;

use App\Exceptions\ScholarshipConfigPendingException;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * sdd/perfil-beca-montos-admin, PR1 — a profile whose config_set_at marker
 * is NULL (amounts never saved by Administración) must not generate
 * refrends on any of the creation paths.
 */
class GenerateMonthlyRefrendsServiceConfigPendingTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_PENDING = 'El becario no tiene montos de beca configurados por Administración.';

    private GenerateMonthlyRefrendsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $this->service = $this->app->make(GenerateMonthlyRefrendsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProfile(bool $configured, array $profileOverrides = []): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        $factory = ScholarshipProfile::factory();
        if (!$configured) {
            $factory = $factory->configPending();
        }

        return $factory->create(array_merge([
            'user_id'        => $user->id,
            'monthly_amount' => 2000,
            'monto_apoyo'    => 0,
        ], $profileOverrides));
    }

    /** @test */
    public function generate_for_user_throws_on_a_pending_profile_and_creates_nothing(): void
    {
        $profile = $this->makeProfile(false);

        try {
            $this->service->generateForUser($profile, 2026, 9);
            $this->fail('Expected ScholarshipConfigPendingException.');
        } catch (ScholarshipConfigPendingException $e) {
            $this->assertSame(self::MSG_PENDING, $e->getMessage());
            $this->assertSame('SCHOLARSHIP_CONFIG_PENDING', ScholarshipConfigPendingException::CODE);
            $this->assertInstanceOf(\DomainException::class, $e);
        }

        $this->assertSame(0, ScholarshipRefrend::count());
    }

    /** @test */
    public function generate_future_for_advance_throws_on_a_pending_profile_and_creates_nothing(): void
    {
        $profile = $this->makeProfile(false);

        $this->expectException(ScholarshipConfigPendingException::class);
        try {
            $this->service->generateFutureForAdvance($profile, 2026, 12);
        } finally {
            $this->assertSame(0, ScholarshipRefrend::count());
        }
    }

    /** @test */
    public function bulk_counts_pending_separately_and_continues_with_configured_profiles(): void
    {
        $this->makeProfile(true);
        $this->makeProfile(true);
        $pending = $this->makeProfile(false);
        $pending->user->update(['first_name' => 'Ana', 'last_name' => 'Pendiente']);

        Log::shouldReceive('error')->never();

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertSame(2, $stats['created']);
        $this->assertSame(1, $stats['skipped_config_pending']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertCount(1, $stats['failures']);
        $this->assertSame([
            'user_id' => $pending->user_id,
            'name'    => 'Ana Pendiente',
            'reason'  => self::MSG_PENDING,
            'kind'    => 'skipped_config_pending',
        ], $stats['failures'][0]);
    }

    /** @test */
    public function bulk_always_reports_skipped_config_pending_key_with_zero_default(): void
    {
        $this->makeProfile(true);

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertArrayHasKey('skipped_config_pending', $stats);
        $this->assertSame(0, $stats['skipped_config_pending']);
        $this->assertSame(1, $stats['created']);
    }

    /** @test */
    public function a_cycle_closed_profile_keeps_the_cycle_closed_error_even_if_pending(): void
    {
        $profile = $this->makeProfile(false, ['cycle_closed_period' => '2026-06-01']);

        $this->expectException(\App\Exceptions\ScholarshipCycleClosedException::class);
        $this->service->generateForUser($profile, 2026, 9);
    }

    /** @test */
    public function a_configured_profile_still_generates_with_unchanged_amounts(): void
    {
        $profile = $this->makeProfile(true);

        $refrend = $this->service->generateForUser($profile, 2026, 9);

        $this->assertNotNull($refrend);
        $this->assertEquals(2000, (float) $refrend->base_amount);
    }

    /** @test */
    public function a_configured_profile_with_zero_amounts_still_generates_a_zero_refrend(): void
    {
        $profile = $this->makeProfile(true, ['monthly_amount' => 0, 'monto_apoyo' => 0]);

        $refrend = $this->service->generateForUser($profile, 2026, 9);

        $this->assertNotNull($refrend);
        $this->assertEquals(0, (float) $refrend->base_amount);
    }
}
