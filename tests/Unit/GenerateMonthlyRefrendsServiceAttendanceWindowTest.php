<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipLateConsumption;
use App\Models\ScholarshipProfile;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/refrendo-mes-siguiente PR2 (task 2.4): generation evaluates lates and
 * absences over AttendanceWindow::endingAt(now()), never over the refrend
 * month. The advance-payment path keeps its legacy behavior.
 *
 * The period cap (current month + 1) is PR3; these tests only generate the
 * current or a past month.
 */
class GenerateMonthlyRefrendsServiceAttendanceWindowTest extends TestCase
{
    use RefreshDatabase;

    private GenerateMonthlyRefrendsService $service;
    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service    = $this->app->make(GenerateMonthlyRefrendsService::class);
        $this->generation = Generation::create([
            'generation_name'   => 'Test Gen',
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProfile(): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true,
            'generation_id' => $this->generation->id,
        ]);

        return ScholarshipProfile::create([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => '2026-01-01',
            'config_set_at'       => now(),
        ]);
    }

    private function attend(ScholarshipProfile $profile, string $date, string $status): Attendance
    {
        $class = ClassModel::create([
            'name' => 'Clase', 'date' => $date, 'start_time' => '08:00:00', 'end_time' => '09:00:00',
            'campus' => 'MERIDA', 'generation_id' => $this->generation->id,
        ]);

        return Attendance::create(['user_id' => $profile->user_id, 'class_id' => $class->id, 'status' => $status]);
    }

    /** @test */
    public function an_october_absence_suspends_the_refrend_generated_on_29_oct_and_is_consumed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $profile = $this->makeProfile();
        $absence = $this->attend($profile, '2026-10-20', 'ABSENT');
        $this->attend($profile, '2026-10-30', 'ABSENT'); // after the reference date

        $refrend = $this->service->generateForUser($profile, 2026, 10);

        $discount = $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->first();
        $this->assertNotNull($discount);
        $this->assertSame(
            [$absence->id],
            ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $discount->id)->pluck('attendance_id')->all()
        );
        $this->assertSame(1, $refrend->attendance_summary_snapshot['month_absent']);
    }

    /** @test */
    public function the_window_follows_the_generation_day_not_the_refrend_month(): void
    {
        // A September refrend generated late (29/Oct) still sees the October absence.
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $profile = $this->makeProfile();
        $this->attend($profile, '2026-10-20', 'ABSENT');
        $this->attend($profile, '2026-07-20', 'ABSENT'); // previous semester

        $refrend = $this->service->generateForUser($profile, 2026, 9);

        $this->assertSame(1, $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->count());
        $this->assertSame(1, ScholarshipLateConsumption::count());
    }

    /** @test */
    public function lates_from_august_to_october_trigger_retardos_on_29_oct(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $profile = $this->makeProfile();
        $this->attend($profile, '2026-08-10', 'LATE');
        $this->attend($profile, '2026-10-05', 'LATE');

        $refrend = $this->service->generateForUser($profile, 2026, 10);

        $this->assertSame(1, $refrend->discounts()->where('discount_type', 'RETARDOS')->count());
        $this->assertSame(2, ScholarshipLateConsumption::count());
    }

    /** @test */
    public function late_pair_and_absence_both_apply_in_the_same_refrend(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $profile = $this->makeProfile();
        $this->attend($profile, '2026-09-01', 'LATE');
        $this->attend($profile, '2026-09-02', 'LATE');
        $this->attend($profile, '2026-10-20', 'ABSENT');
        $this->attend($profile, '2026-10-21', 'ABSENT');

        $refrend = $this->service->generateForUser($profile, 2026, 10);

        $this->assertSame(1, $refrend->discounts()->where('discount_type', 'RETARDOS')->count());
        $this->assertSame(1, $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->count());
        $this->assertSame(4, ScholarshipLateConsumption::count());
        $this->assertSame('0.00', (string) $refrend->final_amount);
    }

    /** @test */
    public function the_advance_payment_path_applies_no_absence_penalty(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $profile = $this->makeProfile();
        $this->attend($profile, '2026-10-20', 'ABSENT');

        $refrend = $this->service->generateFutureForAdvance($profile, 2026, 11);

        $this->assertSame(0, $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->count());
        $this->assertSame(0, ScholarshipLateConsumption::count());
    }

    /** @test */
    public function the_advance_payment_path_still_penalizes_lates_of_the_semester(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $profile = $this->makeProfile();
        $this->attend($profile, '2026-09-01', 'LATE');
        $this->attend($profile, '2026-09-02', 'LATE');

        $refrend = $this->service->generateFutureForAdvance($profile, 2026, 11);

        $this->assertSame(1, $refrend->discounts()->where('discount_type', 'RETARDOS')->count());
    }
}
