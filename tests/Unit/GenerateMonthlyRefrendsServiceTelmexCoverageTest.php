<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\TelmexCoverage;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-iu, PR1 bugfix (design D2, verified 2026-10-09):
 * buildSnapshot() has always computed snapshot_telmex_covered_amount (since
 * sdd/scholarship-telmex-iu-split) but createRefrendForPeriod()'s create()
 * array never persisted it — a generated TELMEX/TELMEX_IU refrend silently
 * lost its covered bookkeeping. snapshot_telmex_coverage_id is new in this
 * PR and must be wired at the same time.
 */
class GenerateMonthlyRefrendsServiceTelmexCoverageTest extends TestCase
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
            'scholarship_type'    => ScholarshipType::TELMEX->value,
            'monthly_amount'      => 1800.00,
            'monto_apoyo'         => 150.00,
            'reticula_start_date' => null,
            'reticula_end_date'   => null,
            'payment_start_date'  => now()->toDateString(),
        ], $overrides));
    }

    /** @test */
    public function generate_for_user_persists_the_covered_bookkeeping_amount_for_pure_telmex(): void
    {
        $now     = Carbon::now();
        $profile = $this->makeProfile();

        $refrend = $this->service->generateForUser($profile, $now->year, $now->month);

        $this->assertNotNull($refrend);
        $this->assertSame(1950.0, (float) $refrend->snapshot_telmex_covered_amount);
        $this->assertDatabaseHas('scholarship_refrends', [
            'id'                             => $refrend->id,
            'snapshot_telmex_covered_amount' => 1950.00,
        ]);
    }

    /** @test */
    public function generate_for_user_persists_the_coverage_fk_when_an_active_coverage_applies(): void
    {
        $now     = Carbon::now();
        $profile = $this->makeProfile();

        $coverage = TelmexCoverage::create([
            'user_id'                        => $profile->user_id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => $now->copy()->startOfMonth()->toDateString(),
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
        ]);

        $refrend = $this->service->generateForUser($profile, $now->year, $now->month);

        $this->assertNotNull($refrend);
        $this->assertSame($coverage->id, $refrend->snapshot_telmex_coverage_id);
        $this->assertDatabaseHas('scholarship_refrends', [
            'id'                           => $refrend->id,
            'snapshot_telmex_coverage_id'  => $coverage->id,
        ]);
    }

    /** @test */
    public function generate_for_user_persists_null_covered_bookkeeping_for_iu(): void
    {
        $now     = Carbon::now();
        $profile = $this->makeProfile(['scholarship_type' => ScholarshipType::IU->value]);

        $refrend = $this->service->generateForUser($profile, $now->year, $now->month);

        $this->assertNotNull($refrend);
        $this->assertNull($refrend->snapshot_telmex_covered_amount);
        $this->assertNull($refrend->snapshot_telmex_coverage_id);
    }
}
