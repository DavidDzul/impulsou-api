<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipLateConsumption;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use App\Services\RecalculateRefrendService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/refrendo-mes-siguiente PR2 (task 2.5): recalculation uses the refrend's
 * created_at as reference date and only deletes consumption-backed (automatic)
 * discounts, so manual retentions survive.
 */
class RecalculateRefrendServiceAttendanceWindowTest extends TestCase
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

    /** @test */
    public function recalculating_later_does_not_count_events_after_the_creation_date(): void
    {
        $refrend = $this->generateOn29Oct();
        $this->assertSame(0, $refrend->discounts()->count());

        $this->attend('2026-11-02', 'ABSENT');
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 10));

        $recalculated = $this->recalc($refrend);

        $this->assertSame(0, $recalculated->discounts()->count());
        $this->assertSame(0, $recalculated->attendance_summary_snapshot['month_absent']);
    }

    /** @test */
    public function an_absence_justified_after_generation_lifts_the_suspension_on_recalculate(): void
    {
        $absence = $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->generateOn29Oct();
        $this->assertSame(1, $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->count());

        $absence->update(['status' => 'JUSTIFIED_ABSENCE']);
        $recalculated = $this->recalc($refrend);

        $this->assertSame(0, $recalculated->discounts()->count());
        $this->assertSame(0, ScholarshipLateConsumption::count());
        $this->assertSame('2000.00', (string) $recalculated->final_amount);
    }

    /** @test */
    public function a_manual_retention_falta_discount_survives_recalculation(): void
    {
        $refrend = $this->generateOn29Oct();
        $manual  = $refrend->discounts()->create([
            'discount_type' => 'FALTA_INJUSTIFICADA', 'discount_percentage' => 40, 'description' => 'Manual',
        ]);

        $recalculated = $this->recalc($refrend);

        $this->assertNotNull($recalculated->discounts()->find($manual->id));
    }

    /** @test */
    public function a_forgiven_absence_is_re_penalized_by_recalculation_without_duplicating_consumption(): void
    {
        $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->generateOn29Oct();
        $this->app->make(\App\Services\AttendancePenaltyService::class)->neutralizeAttendancePenalties($refrend);

        $recalculated = $this->recalc($refrend);

        $discount = $recalculated->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->first();
        $this->assertSame('100.00', (string) $discount->discount_percentage);
        $this->assertSame(1, ScholarshipLateConsumption::count());
    }

    /** @test */
    public function recalculation_is_idempotent_and_keeps_its_own_consumption_in_the_summary(): void
    {
        $this->attend('2026-10-20', 'ABSENT');
        $this->attend('2026-10-21', 'ABSENT');
        $refrend = $this->generateOn29Oct();

        $first  = $this->recalc($refrend);
        $second = $this->recalc($refrend);

        foreach ([$first, $second] as $result) {
            $this->assertSame(1, $result->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->count());
            $this->assertSame(2, ScholarshipLateConsumption::count());
            $this->assertSame(2, $result->attendance_summary_snapshot['month_absent']);
        }
    }
}
