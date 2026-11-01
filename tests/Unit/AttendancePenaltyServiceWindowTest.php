<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipLateConsumption;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\AttendancePenaltyService;
use App\Services\Scholarship\AttendanceWindow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/refrendo-mes-siguiente PR2 (tasks 2.1 - 2.3): lates and absences are
 * evaluated over an AttendanceWindow, and ALL unconsumed absences in the
 * window are consumed by ONE 100% FALTA_INJUSTIFICADA discount.
 */
class AttendancePenaltyServiceWindowTest extends TestCase
{
    use RefreshDatabase;

    private AttendancePenaltyService $service;
    private Generation $generation;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service    = $this->app->make(AttendancePenaltyService::class);
        $this->generation = Generation::create([
            'generation_name'   => 'Test Gen',
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);
        $this->user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
    }

    private function attend(string $date, string $status, ?User $user = null): Attendance
    {
        $class = ClassModel::create([
            'name'          => 'Clase de prueba',
            'date'          => $date,
            'start_time'    => '08:00:00',
            'end_time'      => '09:00:00',
            'campus'        => 'MERIDA',
            'generation_id' => $this->generation->id,
        ]);

        return Attendance::create(['user_id' => ($user ?? $this->user)->id, 'class_id' => $class->id, 'status' => $status]);
    }

    private function refrend(int $month = 11): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'                      => $this->user->id,
            'period_year'                  => 2026,
            'period_month'                 => $month,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test',
            'snapshot_gross_amount'        => 1000,
            'snapshot_monto_apoyo'         => 0,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);
    }

    // ── 2.1 scope ────────────────────────────────────────────────────────────

    /** @test */
    public function unconsumed_absences_scope_returns_only_absent_unconsumed_rows_inside_the_range(): void
    {
        $inRange   = $this->attend('2026-09-10', 'ABSENT');
        $consumed  = $this->attend('2026-09-11', 'ABSENT');
        $this->attend('2026-09-12', 'JUSTIFIED_ABSENCE');
        $this->attend('2026-09-13', 'LATE');
        $this->attend('2026-07-31', 'ABSENT');   // before range
        $this->attend('2026-11-02', 'ABSENT');   // future, after range end

        $discount = $this->refrend()->discounts()->create([
            'discount_type'       => 'FALTA_INJUSTIFICADA',
            'discount_percentage' => 100,
        ]);
        ScholarshipLateConsumption::create([
            'scholarship_refrend_discount_id' => $discount->id,
            'attendance_id'                   => $consumed->id,
            'created_at'                      => now(),
        ]);

        $ids = Attendance::where('user_id', $this->user->id)
            ->unconsumedAbsencesInRange('2026-08-01', '2026-10-29')
            ->pluck('id')
            ->all();

        $this->assertSame([$inRange->id], $ids);
    }

    // ── 2.2 lates over the window ────────────────────────────────────────────

    /** @test */
    public function lates_from_august_to_october_trigger_the_penalty_for_a_window_ending_29_oct(): void
    {
        $this->attend('2026-08-10', 'LATE');
        $this->attend('2026-10-05', 'LATE');
        $window = new AttendanceWindow('2026-08-01', '2026-10-29');

        $discount = $this->service->applyPenaltyIfDue($this->refrend(), $this->user, 100.0, $window);

        $this->assertNotNull($discount);
        $this->assertSame('RETARDOS', $discount->discount_type->value ?? $discount->discount_type);
        $this->assertSame(2, ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $discount->id)->count());
    }

    /** @test */
    public function july_lates_do_not_count_for_a_window_starting_1_aug(): void
    {
        $this->attend('2026-07-10', 'LATE');
        $this->attend('2026-07-11', 'LATE');

        $result = $this->service->applyPenaltyIfDue(
            $this->refrend(8),
            $this->user,
            100.0,
            AttendanceWindow::endingAt(\Carbon\Carbon::create(2026, 8, 1))
        );

        $this->assertNull($result);
    }

    /** @test */
    public function consumed_lates_are_not_reused_by_a_later_window(): void
    {
        $this->attend('2026-08-10', 'LATE');
        $this->attend('2026-08-11', 'LATE');
        $window = new AttendanceWindow('2026-08-01', '2026-10-29');

        $this->assertNotNull($this->service->applyPenaltyIfDue($this->refrend(10), $this->user, 100.0, $window));
        $this->assertNull($this->service->applyPenaltyIfDue($this->refrend(11), $this->user, 100.0, $window));
    }

    // ── 2.3 absences ─────────────────────────────────────────────────────────

    /** @test */
    public function one_absence_creates_a_full_suspension_and_one_consumption(): void
    {
        $absence = $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->refrend();

        $discount = $this->service->applyAbsencePenaltyIfDue($refrend, $this->user, new AttendanceWindow('2026-08-01', '2026-10-29'));

        $this->assertNotNull($discount);
        $this->assertSame('100.00', (string) $discount->fresh()->discount_percentage);
        $this->assertSame('FALTA_INJUSTIFICADA', (string) ($discount->discount_type->value ?? $discount->discount_type));
        $this->assertSame([$absence->id], ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $discount->id)->pluck('attendance_id')->all());
    }

    /** @test */
    public function three_absences_create_one_discount_and_consume_all_three(): void
    {
        $this->attend('2026-08-20', 'ABSENT');
        $this->attend('2026-09-20', 'ABSENT');
        $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->refrend();

        $discount = $this->service->applyAbsencePenaltyIfDue($refrend, $this->user, new AttendanceWindow('2026-08-01', '2026-10-29'));

        $this->assertSame(1, $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->count());
        $this->assertSame(3, ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $discount->id)->count());
    }

    /** @test */
    public function an_already_consumed_absence_does_not_penalize_again(): void
    {
        $this->attend('2026-10-20', 'ABSENT');
        $window = new AttendanceWindow('2026-08-01', '2026-10-29');

        $this->assertNotNull($this->service->applyAbsencePenaltyIfDue($this->refrend(10), $this->user, $window));
        $this->assertNull($this->service->applyAbsencePenaltyIfDue($this->refrend(11), $this->user, $window));
    }

    /** @test */
    public function justified_absences_and_previous_semester_absences_are_ignored(): void
    {
        $this->attend('2026-10-20', 'JUSTIFIED_ABSENCE');
        $this->attend('2026-07-20', 'ABSENT');

        $result = $this->service->applyAbsencePenaltyIfDue(
            $this->refrend(),
            $this->user,
            new AttendanceWindow('2026-08-01', '2026-10-29')
        );

        $this->assertNull($result);
    }

    /** @test */
    public function lates_and_absence_in_the_same_refrend_both_apply_and_are_consumed(): void
    {
        $this->attend('2026-09-01', 'LATE');
        $this->attend('2026-09-02', 'LATE');
        $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->refrend();
        $window  = new AttendanceWindow('2026-08-01', '2026-10-29');

        $lates   = $this->service->applyPenaltyIfDue($refrend, $this->user, 100.0, $window);
        $absence = $this->service->applyAbsencePenaltyIfDue($refrend, $this->user, $window);

        $this->assertNotNull($lates);
        $this->assertNotNull($absence);
        $this->assertSame(3, ScholarshipLateConsumption::count());
    }

    /** @test */
    public function a_duplicate_consumption_of_the_same_absence_violates_the_unique_constraint(): void
    {
        $absence  = $this->attend('2026-10-20', 'ABSENT');
        $window   = new AttendanceWindow('2026-08-01', '2026-10-29');
        $this->service->applyAbsencePenaltyIfDue($this->refrend(10), $this->user, $window);

        $other = $this->refrend(11);

        $this->expectException(QueryException::class);
        DB::transaction(function () use ($other, $absence) {
            $discount = $other->discounts()->create(['discount_type' => 'FALTA_INJUSTIFICADA', 'discount_percentage' => 100]);
            ScholarshipLateConsumption::create([
                'scholarship_refrend_discount_id' => $discount->id,
                'attendance_id'                   => $absence->id,
                'created_at'                      => now(),
            ]);
        });
    }
}
