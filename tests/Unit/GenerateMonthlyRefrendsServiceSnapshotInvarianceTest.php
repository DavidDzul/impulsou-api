<?php

namespace Tests\Unit;

use App\Enums\DiscountType;
use App\Enums\ScholarshipType;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Generation;
use App\Models\ScholarshipProfile;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/verificacion-incidencias-ux, PR1, task 1.1 — RED FIRST, committed
 * against the UNCHANGED GenerateMonthlyRefrendsService. This golden-fixture
 * test must pass BOTH before and after the PR1 reporting change (new
 * ReticulaPeriodOutOfRangeException, failures[] classification). PR1 is
 * only allowed to touch generateForPeriod()'s loop and
 * assertPeriodWithinReticula()'s throw class — it must NEVER touch
 * buildSnapshot(), recalculate(), or any step inside
 * createRefrendForPeriod(). This test is the trip-wire for that boundary:
 * if any snapshot_* field, discount row, incident row, or amount changes,
 * this test goes red and the PR1 diff must be reverted to stop touching
 * snapshot logic.
 *
 * Golden fixture combines three independent snapshot/calc concerns in one
 * becario so a single assertion set covers all of them:
 *  - an active academic discount (30%) -> DESCUENTO_PERFIL incident +
 *    snapshot_discount_percentage/reason
 *  - an active temporary increase (+500) -> snapshot_temporary_increase_*
 *    and snapshot_gross_amount
 *  - two unconsumed semester lates -> RETARDOS penalty discount (100%,
 *    per createRefrendForPeriod's hardcoded penaltyPercentage), which caps
 *    the combined discount at 100% and zeroes final_amount — this is the
 *    actual, current behavior of unchanged code and must stay identical.
 */
class GenerateMonthlyRefrendsServiceSnapshotInvarianceTest extends TestCase
{
    use RefreshDatabase;

    private GenerateMonthlyRefrendsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(GenerateMonthlyRefrendsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeClass(string $campus, string $date, int $generationId): ClassModel
    {
        return ClassModel::create([
            'name'          => 'Clase de prueba',
            'date'          => $date,
            'start_time'    => '08:00:00',
            'end_time'      => '09:00:00',
            'campus'        => $campus,
            'generation_id' => $generationId,
        ]);
    }

    private function makeAttendance(int $userId, int $classId, string $status): void
    {
        Attendance::create([
            'user_id'  => $userId,
            'class_id' => $classId,
            'status'   => $status,
        ]);
    }

    /** @test */
    public function generate_for_user_produces_byte_identical_snapshot_and_amounts_for_the_golden_fixture(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15));

        $generation = Generation::create([
            'generation_name'   => 'Gen Oct 2026',
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);

        $user = User::factory()->create([
            'user_type'     => 'BEC_ACTIVE',
            'campus'        => 'MERIDA',
            'active'        => true,
            'generation_id' => $generation->id,
            'first_name'    => 'Ana',
            'last_name'     => 'Pérez',
        ]);

        $profile = ScholarshipProfile::create([
            'user_id'                        => $user->id,
            'scholarship_type'                => ScholarshipType::IU->value,
            'monthly_amount'                   => 2000.00,
            'monto_apoyo'                       => 100.00,
            'reticula_start_date'               => '2000-01-01',
            'reticula_end_date'                 => '2099-12-31',
            'payment_start_date'                => '2026-01-01',
            'config_set_at'        => now(),
            'active_discount_percentage'        => 30,
            'discount_reason'                    => 'Bajo rendimiento académico',
            'discount_valid_from'               => '2026-01-01',
            'discount_valid_until'               => '2027-01-01',
            'temporary_increase_amount'          => 500.00,
            'temporary_increase_valid_from'      => '2026-10-01',
            'temporary_increase_valid_until'      => '2026-10-31',
            'temporary_increase_reason'          => 'Apoyo transporte',
        ]);

        DB::table('scholarship_semester_grades')->insert([
            'user_id'         => $user->id,
            'semester_year'   => 2026,
            'semester_period' => 2,
            'grade'           => 8.5,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // Two unconsumed semester lates (current semester = Aug-Dic 2026) —
        // triggers the RETARDOS penalty inside createRefrendForPeriod().
        $classOne = $this->makeClass('MERIDA', '2026-08-10', $generation->id);
        $classTwo = $this->makeClass('MERIDA', '2026-08-11', $generation->id);
        $this->makeAttendance($user->id, $classOne->id, 'LATE');
        $this->makeAttendance($user->id, $classTwo->id, 'LATE');

        $refrend = $this->service->generateForUser($profile, 2026, 10);

        $this->assertNotNull($refrend);

        // ── snapshot_* fields (golden values) ────────────────────────────
        $this->assertSame('Ana Pérez', $refrend->snapshot_name);
        $this->assertSame('Gen Oct 2026', $refrend->snapshot_generation);
        $this->assertSame($generation->id, $refrend->snapshot_generation_id);
        $this->assertSame('MERIDA', $refrend->snapshot_campus);
        $this->assertSame(ScholarshipType::IU, $refrend->snapshot_scholarship_type);
        // gross = monthly_amount(2000) + monto_apoyo(100) + increase(500)
        $this->assertSame('2600.00', (string) $refrend->snapshot_gross_amount);
        $this->assertSame('100.00', (string) $refrend->snapshot_monto_apoyo);
        $this->assertNull($refrend->snapshot_telmex_covered_amount);
        $this->assertNull($refrend->snapshot_telmex_coverage_id);
        // base_amount = monthly_amount only, per buildSnapshot()'s IU branch
        $this->assertSame('2000.00', (string) $refrend->base_amount);
        $this->assertSame('30.00', (string) $refrend->snapshot_discount_percentage);
        $this->assertSame('Bajo rendimiento académico', $refrend->snapshot_discount_reason);
        $this->assertSame('500.00', (string) $refrend->snapshot_temporary_increase_amount);
        $this->assertSame('Apoyo transporte', $refrend->snapshot_temporary_increase_reason);
        $this->assertSame(8.5, (float) $refrend->average_grade_snapshot);
        $this->assertSame(0, $refrend->missing_subjects_snapshot);

        // ── attendance_summary_snapshot (golden values) ──────────────────
        $attendanceSummary = is_array($refrend->attendance_summary_snapshot)
            ? $refrend->attendance_summary_snapshot
            : json_decode($refrend->attendance_summary_snapshot, true);

        $this->assertSame([
            'present'         => 0,
            'late'            => 2,
            'absent'          => 0,
            // attendance_summary_snapshot is built BEFORE the RETARDOS
            // penalty consumes these lates (createRefrendForPeriod computes
            // it first, then runs the penalty service inside the
            // transaction) — so it freezes the pre-consumption state.
            'late_unconsumed' => 2,
            'total'           => 2,
            'month_absent'    => 0,
        ], $attendanceSummary);

        // ── DESCUENTO_PERFIL incident (unchanged by PR1) ─────────────────
        $this->assertSame(1, $refrend->incidents()->count());
        $incident = $refrend->incidents()->first();
        $this->assertSame('ACADEMICO', $incident->incident_category);
        $this->assertSame('DESCUENTO_PERFIL', $incident->incident_type);
        $this->assertStringContainsString('Descuento académico del 30', $incident->description);

        // ── RETARDOS penalty discount row (unchanged by PR1) ─────────────
        $retardos = $refrend->discounts()->where('discount_type', DiscountType::RETARDOS->value)->first();
        $this->assertNotNull($retardos);
        $this->assertSame('100.00', (string) $retardos->discount_percentage);

        // ── final calculated amounts (unchanged by PR1) ──────────────────
        // calculateFinalAmount(): base = gross(2600) reduced by the academic
        // snapshot_discount_percentage(30%) = 1820.00; the RETARDOS discount
        // row (100%) then consumes the entirety of that base -> final 0.
        $this->assertSame('100.00', (string) $refrend->discount_percentage);
        $this->assertSame('1820.00', (string) $refrend->discount_amount);
        $this->assertSame('0.00', (string) $refrend->final_amount);
    }
}
