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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/refrendo-mes-siguiente PR3: the period cap (current month + 1), bulk
 * behavior at the cap, egreso interplay, and the end-to-end client case
 * (generating next month near the end of the current one).
 */
class GenerateMonthlyRefrendsServiceNextMonthTest extends TestCase
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

    private function makeProfile(array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true,
            'generation_id' => $this->generation->id,
        ]);

        return ScholarshipProfile::create(array_merge([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => '2026-01-01',
            'config_set_at'       => now(),
        ], $overrides));
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
    public function bulk_beyond_the_cap_reports_each_becario_as_failed_with_the_same_message_and_keeps_going(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $a = $this->makeProfile();
        $b = $this->makeProfile();

        $stats = $this->service->generateForPeriod(2026, 12, 'MERIDA', null);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(2, $stats['errors']);
        $this->assertCount(2, $stats['failures']);
        $expected = 'Solo puedes generar el refrendo hasta el mes siguiente (noviembre 2026).';
        foreach ($stats['failures'] as $failure) {
            $this->assertSame('error', $failure['kind']);
            $this->assertSame($expected, $failure['reason']);
        }
        $this->assertSame(
            [$a->user_id, $b->user_id],
            collect($stats['failures'])->pluck('user_id')->sort()->values()->all()
        );
        $this->assertSame(0, ScholarshipRefrend::count());
    }

    /** @test */
    public function bulk_skips_a_becario_whose_target_month_was_already_paid_in_advance(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $advanced = $this->makeProfile();
        $fresh    = $this->makeProfile();
        $this->service->generateFutureForAdvance($advanced, 2026, 11);

        $stats = $this->service->generateForPeriod(2026, 11, 'MERIDA', null);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame(1, ScholarshipRefrend::where('user_id', $advanced->user_id)->count());
        $this->assertSame(1, ScholarshipRefrend::where('user_id', $fresh->user_id)->count());
    }

    /** @test */
    public function an_egreso_month_generated_ahead_within_the_cap_graduates_the_becario(): void
    {
        // reticula_end 2026-08-31 -> egreso administrativo 2026-10-31; October is month+2.
        Carbon::setTestNow(Carbon::create(2026, 9, 30, 10));
        $profile = $this->makeProfile(['reticula_end_date' => '2026-08-31']);

        $refrend = $this->service->generateForUser($profile, 2026, 10);

        $this->assertSame('0.00', (string) $refrend->final_amount);
        $this->assertSame(ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA, $refrend->resolution_type);
        $this->assertSame('BEC_INACTIVE', $profile->user->fresh()->user_type);
    }

    /** @test */
    public function the_month_after_the_egreso_is_blocked_by_the_reticula_even_inside_the_cap(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $profile = $this->makeProfile(['reticula_end_date' => '2026-08-31']);

        try {
            $this->service->generateForUser($profile, 2026, 11);
            $this->fail('Expected the reticula guard to reject the month after the egreso.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('egreso administrativo', $e->getMessage());
        }

        $this->assertSame(0, ScholarshipRefrend::count());
    }

    /** @test */
    public function client_case_november_generated_on_29_oct_counts_september_absence_and_aug_to_oct_lates(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 10));
        $profile = $this->makeProfile();
        $lateAug = $this->attend($profile, '2026-08-05', 'LATE');
        $lateSep = $this->attend($profile, '2026-09-10', 'LATE');
        $this->attend($profile, '2026-10-05', 'LATE');
        $absence = $this->attend($profile, '2026-09-15', 'ABSENT');
        $this->attend($profile, '2026-07-15', 'ABSENT'); // previous semester

        $refrend = $this->service->generateForUser($profile, 2026, 11);

        $falta = $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->first();
        $this->assertNotNull($falta);
        $this->assertSame(
            [$absence->id],
            ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $falta->id)->pluck('attendance_id')->all()
        );

        $retardos = $refrend->discounts()->where('discount_type', 'RETARDOS')->first();
        $this->assertNotNull($retardos);
        $this->assertEqualsCanonicalizing(
            [$lateAug->id, $lateSep->id],
            ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $retardos->id)->pluck('attendance_id')->all()
        );
        $this->assertSame(1, $refrend->attendance_summary_snapshot['month_absent']);
        $this->assertSame('0.00', (string) $refrend->final_amount);
    }

    /** @test */
    public function january_generated_on_29_dec_uses_the_august_to_december_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 29, 10));
        $profile = $this->makeProfile();
        $dec     = $this->attend($profile, '2026-12-10', 'ABSENT');
        $this->attend($profile, '2026-07-10', 'ABSENT'); // previous semester
        $this->attend($profile, '2027-01-05', 'ABSENT'); // after the reference date

        $refrend = $this->service->generateForUser($profile, 2027, 1);

        $falta = $refrend->discounts()->where('discount_type', 'FALTA_INJUSTIFICADA')->first();
        $this->assertNotNull($falta);
        $this->assertSame(
            [$dec->id],
            ScholarshipLateConsumption::where('scholarship_refrend_discount_id', $falta->id)->pluck('attendance_id')->all()
        );
        $this->assertSame(1, ScholarshipLateConsumption::count());
    }
}
