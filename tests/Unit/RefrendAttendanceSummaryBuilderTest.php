<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\AttendancePenaltyService;
use App\Services\Scholarship\AttendanceWindow;
use App\Services\Scholarship\RefrendAttendanceSummaryBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parity with the two private getAttendanceSummaryForPeriod() copies that the
 * builder replaces (GenerateMonthlyRefrendsService, RecalculateRefrendService).
 */
class RefrendAttendanceSummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generation = Generation::create([
            'generation_name'   => 'Test Gen',
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);
    }

    private function attend(User $user, string $date, string $status): void
    {
        $class = ClassModel::create([
            'name'          => 'Clase de prueba',
            'date'          => $date,
            'start_time'    => '08:00:00',
            'end_time'      => '09:00:00',
            'campus'        => 'MERIDA',
            'generation_id' => $this->generation->id,
        ]);

        Attendance::create(['user_id' => $user->id, 'class_id' => $class->id, 'status' => $status]);
    }

    private function seedScenario(User $user): void
    {
        $this->attend($user, '2025-12-20', 'PRESENT');          // before window
        $this->attend($user, '2026-01-10', 'PRESENT');
        $this->attend($user, '2026-01-12', 'ABSENT');           // in window, not in month
        $this->attend($user, '2026-02-05', 'LATE');
        $this->attend($user, '2026-02-06', 'LATE');
        $this->attend($user, '2026-02-07', 'JUSTIFIED_LATE');
        $this->attend($user, '2026-02-20', 'LATE');
        $this->attend($user, '2026-03-03', 'ABSENT');           // in month
        $this->attend($user, '2026-03-04', 'JUSTIFIED_ABSENCE');
        $this->attend($user, '2026-03-25', 'PRESENT');          // after window end
    }

    public function test_summary_matches_legacy_semantics_for_a_semester_window(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $this->seedScenario($user);

        $refrend = ScholarshipRefrend::create([
            'user_id'                   => $user->id,
            'period_year'               => 2026,
            'period_month'              => 3,
            'refrend_type'              => RefrendType::NORMAL->value,
            'status'                    => RefrendStatus::DRAFT->value,
            'workflow_status'           => 'DRAFT',
            'base_amount'               => 1000,
            'discount_percentage'       => 0,
            'discount_amount'           => 0,
            'final_amount'              => 1000,
            'amount_pending_from_previous' => 0,
            'snapshot_name'             => 'Test',
            'snapshot_gross_amount'     => 1000,
            'snapshot_monto_apoyo'      => 0,
            'snapshot_campus'           => 'MERIDA',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ]);
        // Consumes the two oldest unjustified lates (Feb 5 and Feb 6).
        $this->app->make(AttendancePenaltyService::class)
            ->applyPenaltyIfDue($refrend, $user, 25.0, Carbon::create(2026, 3, 15));

        $summary = (new RefrendAttendanceSummaryBuilder())->build(
            $user->id,
            new AttendanceWindow('2026-01-01', '2026-03-20'),
            '2026-03-01'
        );

        $this->assertSame([
            'present'         => 1,
            'late'            => 4,
            'absent'          => 3,
            'late_unconsumed' => 1,
            'total'           => 8,
            'month_absent'    => 1,
        ], $summary);
    }

    public function test_summary_is_zeroed_when_user_has_no_attendance(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        $summary = (new RefrendAttendanceSummaryBuilder())->build(
            $user->id,
            new AttendanceWindow('2026-08-01', '2026-10-29'),
            '2026-10-01'
        );

        $this->assertSame([
            'present' => 0, 'late' => 0, 'absent' => 0,
            'late_unconsumed' => 0, 'total' => 0, 'month_absent' => 0,
        ], $summary);
    }
}
