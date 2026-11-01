<?php

namespace Tests\Feature;

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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/refrendo-mes-siguiente PR2 (task 2.6): the attendanceSummary endpoint
 * uses the same window as generation and recalculation.
 */
class ScholarshipRefrendAttendanceSummaryWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $becario;
    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin      = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->becario    = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $this->generation = Generation::create(['generation_name' => 'G', 'campus' => 'MERIDA', 'generation_active' => true]);
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

        return Attendance::create(['user_id' => $this->becario->id, 'class_id' => $class->id, 'status' => $status]);
    }

    private function makeRefrend(int $month): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id' => $this->becario->id, 'period_year' => 2026, 'period_month' => $month,
            'refrend_type' => RefrendType::NORMAL->value, 'created_via' => 'GENERATION',
            'status' => RefrendStatus::DRAFT->value, 'workflow_status' => 'DRAFT',
            'base_amount' => 1000, 'discount_percentage' => 0, 'discount_amount' => 0, 'final_amount' => 1000,
            'amount_pending_from_previous' => 0, 'snapshot_name' => 'T', 'snapshot_gross_amount' => 1000,
            'snapshot_monto_apoyo' => 0, 'snapshot_campus' => 'MERIDA',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ]);
    }

    private function summary(int $year, int $month): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$this->becario->id}/attendance-summary?year={$year}&month={$month}");
        $response->assertOk();

        return $response->json('data');
    }

    /** @test */
    public function without_a_refrend_the_window_ends_today_and_reports_unconsumed_absences(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $this->attend('2026-10-20', 'ABSENT');
        $this->attend('2026-10-30', 'ABSENT');   // after today
        $this->attend('2026-07-20', 'ABSENT');   // previous semester

        $data = $this->summary(2026, 11);

        $this->assertSame('2026-08-01', $data['semester_start']);
        $this->assertSame('2026-10-29', $data['semester_end']);
        $this->assertSame(1, $data['absent_unjustified']);
        $this->assertSame(1, $data['absent_unconsumed']);
        $this->assertSame(0, $data['absence_consumed']);
    }

    /** @test */
    public function records_expose_check_in_and_check_out_times(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $present = $this->attend('2026-10-20', 'PRESENT');
        $present->update(['check_in' => '08:05', 'check_out' => '09:00']);
        $absence = $this->attend('2026-10-21', 'ABSENT');

        $data = $this->summary(2026, 11);

        $withTimes = collect($data['records'])->firstWhere('id', $present->id);
        $this->assertSame('08:05', substr((string) $withTimes['check_in'], 0, 5));
        $this->assertSame('09:00', substr((string) $withTimes['check_out'], 0, 5));
        $noTimes = collect($data['records'])->firstWhere('id', $absence->id);
        $this->assertArrayHasKey('check_in', $noTimes);
        $this->assertNull($noTimes['check_in']);
        $this->assertNull($noTimes['check_out']);
    }

    /** @test */
    public function an_existing_refrend_uses_its_created_at_as_reference_date(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $absence = $this->attend('2026-10-20', 'ABSENT');
        $refrend = $this->makeRefrend(10);
        $this->app->make(AttendancePenaltyService::class)
            ->applyAbsencePenaltyIfDue($refrend, $this->becario, AttendanceWindow::endingAt(now()));
        $this->attend('2026-11-02', 'ABSENT');
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 10));

        $data = $this->summary(2026, 10);

        $this->assertSame('2026-10-29', $data['semester_end']);
        $this->assertSame(1, $data['absent_unjustified']);
        $this->assertSame(1, $data['absence_consumed']);
        $this->assertSame(0, $data['absent_unconsumed']);
        $this->assertSame(0, $data['late_consumed']);
        $record = collect($data['records'])->firstWhere('id', $absence->id);
        $this->assertTrue($record['late_penalty_consumed']);
    }
}
