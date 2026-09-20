<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both generateForPeriod() (bulk) and generateForUser() gate on
 * users.active only — never user_type. generateForUser() used to skip the
 * check entirely (reachable directly via the per-becario generate endpoint,
 * currently unused by the UI but a live API route); generateForPeriod()
 * used to also require user_type=BEC_ACTIVE, which would incorrectly stop
 * a becario still owed months inside their "egreso administrativo" grace
 * window if user_type were separately flipped (e.g. the legacy graduate()
 * action). active=false ("permanently withdrawn", set by
 * RecordPaymentSituationAction's BAJA_DEFINITIVA) is the only real stop
 * signal. User-requested 2026-09-20.
 */
class GenerateMonthlyRefrendsServiceActiveUserTest extends TestCase
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
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ], $userOverrides));

        return ScholarshipProfile::create(array_merge([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => null,
            'reticula_end_date'   => null,
            'payment_start_date'  => now()->subYear()->toDateString(),
        ], $profileOverrides));
    }

    /** @test */
    public function throws_a_domain_exception_when_the_user_is_inactive(): void
    {
        $profile = $this->makeProfile(['active' => false]);

        $this->expectException(\DomainException::class);

        $this->service->generateForUser($profile, now()->year, now()->month);
    }

    /**
     * Deliberately does NOT gate on user_type — a becario can legitimately
     * graduate (user_type changing away from BEC_ACTIVE, a separate, still-
     * undecided concern) while still owed pending months within their
     * reticula window. Only active=false ("permanently withdrawn") blocks
     * generation.
     *
     * @test
     */
    public function does_not_throw_for_a_non_bec_active_user_type_as_long_as_active_is_true(): void
    {
        $profile = $this->makeProfile(['user_type' => 'BEC_INACTIVE', 'active' => true]);

        $refrend = $this->service->generateForUser($profile, now()->year, now()->month);

        $this->assertNotNull($refrend);
    }

    /** @test */
    public function does_not_throw_for_an_active_bec_active_user(): void
    {
        $profile = $this->makeProfile();

        $refrend = $this->service->generateForUser($profile, now()->year, now()->month);

        $this->assertNotNull($refrend);
    }

    // ── generateForPeriod() (bulk) — same active-only rule, no user_type gate ──
    //
    // Mirrors generateForUser()'s guard: user_type is deliberately not part of
    // the bulk query's WHERE either. A becario whose reticula ended but is
    // still inside the 2-month "egreso administrativo" grace window must keep
    // getting generated/paid 100% for those remaining months, even if user_type
    // was separately flipped away from BEC_ACTIVE (e.g. the legacy graduate()
    // action) — only active=false ("permanently withdrawn") should stop it.

    /** @test */
    public function bulk_generation_excludes_an_inactive_user(): void
    {
        $this->makeProfile(['active' => false], ['reticula_end_date' => null]);

        $stats = $this->service->generateForPeriod(now()->year, now()->month, 'MERIDA', null);

        $this->assertSame(0, $stats['created']);
    }

    /** @test */
    public function bulk_generation_includes_a_bec_inactive_but_still_active_user(): void
    {
        $this->makeProfile(
            ['user_type' => 'BEC_INACTIVE', 'active' => true, 'campus' => 'MERIDA'],
        );

        $stats = $this->service->generateForPeriod(now()->year, now()->month, 'MERIDA', null);

        $this->assertSame(1, $stats['created']);
    }
}
