<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\RecalculateRefrendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateRefrendServiceTemporaryIncreaseTest extends TestCase
{
    use RefreshDatabase;

    private RecalculateRefrendService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(RecalculateRefrendService::class);

        // ScholarshipLoggingService::log() requires an authenticated user
        // (performed_by_id is NOT NULL).
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
            'scholarship_type'   => ScholarshipType::IU->value,
            'monthly_amount'     => 2000.00,
            'monto_apoyo'        => 0,
            'payment_start_date' => now()->toDateString(),
        ], $overrides));
    }

    /**
     * Creates a DRAFT refrend with the "old" snapshot shape (no temporary
     * increase captured), simulating a refrend generated before the increase
     * was registered on the profile.
     */
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
            'snapshot_gross_amount'        => 2000.00,
            'snapshot_monto_apoyo'         => 0,
            'base_amount'                  => 2000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 2000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);
    }

    /** @test */
    public function full_recalculate_picks_up_a_temporary_increase_added_after_generation(): void
    {
        $profile = $this->makeProfile();
        $refrend = $this->makeDraftRefrend($profile->user_id);

        // Register a temporary increase AFTER the refrend was generated,
        // active as of "today" (relative bounds so this is stable regardless
        // of the day the suite runs).
        $profile->update([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => Carbon::yesterday()->toDateString(),
            'temporary_increase_valid_until' => Carbon::tomorrow()->toDateString(),
            'temporary_increase_reason'      => 'Apoyo transporte',
        ]);

        $recalculated = $this->service->fullRecalculate($refrend);

        $this->assertSame(2500.0, (float) $recalculated->snapshot_gross_amount);
        $this->assertSame(500.0, (float) $recalculated->snapshot_temporary_increase_amount);
        $this->assertSame('Apoyo transporte', $recalculated->snapshot_temporary_increase_reason);
        $this->assertSame(2500.0, (float) $recalculated->final_amount);
    }

    /** @test */
    public function full_recalculate_without_any_temporary_increase_matches_previous_behavior(): void
    {
        $profile = $this->makeProfile();
        $refrend = $this->makeDraftRefrend($profile->user_id);

        $recalculated = $this->service->fullRecalculate($refrend);

        $this->assertSame(2000.0, (float) $recalculated->snapshot_gross_amount);
        $this->assertNull($recalculated->snapshot_temporary_increase_amount);
        $this->assertNull($recalculated->snapshot_temporary_increase_reason);
        $this->assertSame(2000.0, (float) $recalculated->final_amount);
    }
}
