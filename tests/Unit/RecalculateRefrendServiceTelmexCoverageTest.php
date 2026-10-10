<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use App\Services\RecalculateRefrendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-iu, PR1 bugfix (design D2/decisions-2, verified
 * 2026-10-09): RecalculateRefrendService::fullRecalculate() never persisted
 * snapshot_telmex_covered_amount — a pre-existing gap, confirmed by reading
 * the service before this change. snapshot_telmex_coverage_id is new in
 * this PR and must be wired at the same time.
 */
class RecalculateRefrendServiceTelmexCoverageTest extends TestCase
{
    use RefreshDatabase;

    private RecalculateRefrendService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(RecalculateRefrendService::class);

        $this->actingAs(User::factory()->create(['user_type' => 'ADMIN', 'active' => true]));
    }

    private function makeProfile(array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipProfile::create(array_merge([
            'user_id'            => $user->id,
            'scholarship_type'   => ScholarshipType::TELMEX->value,
            'monthly_amount'     => 1800.00,
            'monto_apoyo'        => 150.00,
            'payment_start_date' => now()->toDateString(),
        ], $overrides));
    }

    private function makeDraftRefrend(int $userId): ScholarshipRefrend
    {
        $now = Carbon::now();

        return ScholarshipRefrend::create([
            'user_id'                      => $userId,
            'period_year'                  => $now->year,
            'period_month'                 => $now->month,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'DRAFT',
            'snapshot_gross_amount'        => 0.0,
            'snapshot_monto_apoyo'         => 150.00,
            'base_amount'                  => 0.0,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 0.0,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::TELMEX->value,
        ]);
    }

    /** @test */
    public function full_recalculate_persists_the_covered_bookkeeping_amount_for_pure_telmex(): void
    {
        $profile = $this->makeProfile();
        $refrend = $this->makeDraftRefrend($profile->user_id);

        $recalculated = $this->service->fullRecalculate($refrend);

        $this->assertSame(1950.0, (float) $recalculated->snapshot_telmex_covered_amount);
        $this->assertDatabaseHas('scholarship_refrends', [
            'id'                             => $refrend->id,
            'snapshot_telmex_covered_amount' => 1950.00,
        ]);
    }

    /** @test */
    public function full_recalculate_persists_the_coverage_fk_when_an_active_coverage_applies(): void
    {
        $profile = $this->makeProfile();
        $refrend = $this->makeDraftRefrend($profile->user_id);

        $coverage = TelmexCoverage::create([
            'user_id'                        => $profile->user_id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => Carbon::now()->startOfMonth()->toDateString(),
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
        ]);

        $recalculated = $this->service->fullRecalculate($refrend);

        $this->assertSame($coverage->id, $recalculated->snapshot_telmex_coverage_id);
        $this->assertSame(1950.0, (float) $recalculated->final_amount);
    }
}
