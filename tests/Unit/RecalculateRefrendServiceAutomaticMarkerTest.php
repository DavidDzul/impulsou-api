<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use App\Services\RecalculateRefrendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/refrendo-mes-siguiente verify-fix: automatic FALTA discounts are
 * identified by the explicit is_automatic marker (not by owning consumption
 * rows), so legacy rows and rows whose attendance was deleted are replaced on
 * recalculation instead of being duplicated, while manual retentions survive.
 */
class RecalculateRefrendServiceAutomaticMarkerTest extends TestCase
{
    use RefreshDatabase;

    private Generation $generation;
    private ScholarshipProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generation = Generation::create([
            'generation_name' => 'Test Gen', 'campus' => 'MERIDA', 'generation_active' => true,
        ]);
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true,
            'generation_id' => $this->generation->id,
        ]);
        $this->profile = ScholarshipProfile::create([
            'user_id' => $user->id, 'scholarship_type' => ScholarshipType::IU->value,
            'monthly_amount' => 2000.00, 'monto_apoyo' => 0,
            'reticula_start_date' => '2000-01-01', 'reticula_end_date' => '2099-12-31',
            'payment_start_date' => '2026-01-01', 'config_set_at' => now(),
        ]);
        $this->actingAs(User::factory()->create(['user_type' => 'ADMIN', 'active' => true]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function attend(string $date, string $status): Attendance
    {
        $class = ClassModel::create([
            'name' => 'Clase', 'date' => $date, 'start_time' => '08:00:00', 'end_time' => '09:00:00',
            'campus' => 'MERIDA', 'generation_id' => $this->generation->id,
        ]);

        return Attendance::create(['user_id' => $this->profile->user_id, 'class_id' => $class->id, 'status' => $status]);
    }

    private function generateOn29Oct(): ScholarshipRefrend
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));

        return $this->app->make(GenerateMonthlyRefrendsService::class)->generateForUser($this->profile, 2026, 10);
    }

    private function recalc(ScholarshipRefrend $refrend): ScholarshipRefrend
    {
        return $this->app->make(RecalculateRefrendService::class)->fullRecalculate($refrend->fresh());
    }

    private function faltaCount(ScholarshipRefrend $refrend): int
    {
        return $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->count();
    }

    /** @test */
    public function the_automatic_absence_penalty_is_flagged_as_automatic(): void
    {
        $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->generateOn29Oct();

        $discount = $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->first();

        $this->assertTrue($discount->is_automatic);
    }

    /** @test */
    public function a_legacy_automatic_falta_without_consumptions_is_replaced_not_duplicated(): void
    {
        $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->generateOn29Oct();
        // Simulate a pre-change row: automatic marker backfilled, no consumptions.
        $refrend->discounts()->delete();
        $refrend->discounts()->create([
            'discount_type' => 'FALTA_INJUSTIFICADA', 'discount_percentage' => 100,
            'description' => 'Suspensión del mes por falta injustificada en el periodo.',
            'is_automatic' => true,
        ]);

        $recalculated = $this->recalc($refrend);

        $this->assertSame(1, $this->faltaCount($recalculated));
        $this->assertSame('0.00', (string) $recalculated->final_amount);
    }

    /** @test */
    public function an_automatic_falta_whose_attendance_was_deleted_is_removed_on_recalculate(): void
    {
        $absence = $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->generateOn29Oct();
        $this->assertSame(1, $this->faltaCount($refrend));

        $absence->delete(); // cascade strips the consumption rows
        $recalculated = $this->recalc($refrend);

        $this->assertSame(0, $this->faltaCount($recalculated));
        $this->assertSame('2000.00', (string) $recalculated->final_amount);
    }

    /** @test */
    public function a_manual_retention_survives_recalculation_alongside_a_single_automatic_falta(): void
    {
        $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->generateOn29Oct();
        $manual = $refrend->discounts()->create([
            'discount_type' => 'FALTA_INJUSTIFICADA', 'discount_percentage' => 40, 'description' => 'Manual',
        ]);

        $recalculated = $this->recalc($refrend);

        $this->assertNotNull($recalculated->discounts()->find($manual->id));
        $this->assertFalse($manual->fresh()->is_automatic);
        $this->assertSame(1, $recalculated->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->where('is_automatic', true)->count());
        $this->assertSame(2, $this->faltaCount($recalculated));
    }

    /** @test */
    public function a_manual_retention_created_by_the_calculation_service_is_not_automatic(): void
    {
        $refrend = $this->generateOn29Oct();

        $discount = $this->app->make(\App\Services\ScholarshipCalculationService::class)->applyRetention($refrend);

        $this->assertFalse($discount->fresh()->is_automatic);
    }
}
