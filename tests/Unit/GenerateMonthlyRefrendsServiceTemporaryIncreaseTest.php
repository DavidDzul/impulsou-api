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
 * Integration coverage for the generation path's own persistence of the two
 * temporary-increase snapshot columns (W2 from sdd-verify): buildSnapshot()
 * and RecalculateRefrendService already had direct coverage, but nothing
 * exercised generateForUser() end-to-end and asserted the created
 * ScholarshipRefrend row in the database actually carries the frozen values.
 */
class GenerateMonthlyRefrendsServiceTemporaryIncreaseTest extends TestCase
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
            // Wide open reticula so only the temporary increase snapshot
            // wiring is exercised, not the reticula range guard.
            'reticula_start_date' => null,
            'reticula_end_date'   => null,
            'payment_start_date'  => now()->toDateString(),
        ], $overrides));
    }

    /** @test */
    public function generate_for_user_persists_the_temporary_increase_snapshot_columns(): void
    {
        $now = Carbon::now();

        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => $now->copy()->startOfMonth()->toDateString(),
            'temporary_increase_valid_until' => $now->copy()->endOfMonth()->toDateString(),
            'temporary_increase_reason'      => 'Apoyo transporte',
        ]);

        $refrend = $this->service->generateForUser($profile, $now->year, $now->month);

        $this->assertNotNull($refrend);
        $this->assertSame(500.0, (float) $refrend->snapshot_temporary_increase_amount);
        $this->assertSame('Apoyo transporte', $refrend->snapshot_temporary_increase_reason);
        $this->assertSame(2500.0, (float) $refrend->snapshot_gross_amount);

        $this->assertDatabaseHas('scholarship_refrends', [
            'id'                                  => $refrend->id,
            'snapshot_temporary_increase_amount'  => 500.00,
            'snapshot_temporary_increase_reason'  => 'Apoyo transporte',
        ]);
    }

    /** @test */
    public function generate_for_user_persists_null_snapshot_columns_when_there_is_no_temporary_increase(): void
    {
        $now     = Carbon::now();
        $profile = $this->makeProfile();

        $refrend = $this->service->generateForUser($profile, $now->year, $now->month);

        $this->assertNotNull($refrend);
        $this->assertNull($refrend->snapshot_temporary_increase_amount);
        $this->assertNull($refrend->snapshot_temporary_increase_reason);

        $this->assertDatabaseHas('scholarship_refrends', [
            'id'                                  => $refrend->id,
            'snapshot_temporary_increase_amount'  => null,
            'snapshot_temporary_increase_reason'  => null,
        ]);
    }
}
