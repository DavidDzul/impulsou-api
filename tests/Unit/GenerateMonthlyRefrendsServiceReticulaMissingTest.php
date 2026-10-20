<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordAdvancePaymentAction;
use App\Enums\RefrendType;
use App\Exceptions\ReticulaPeriodOutOfRangeException;
use App\Exceptions\ScholarshipConfigPendingException;
use App\Exceptions\ScholarshipCycleClosedException;
use App\Exceptions\ScholarshipReticulaMissingException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * sdd/egresar-boton-corte-ciclo, REQ-RG-1/REQ-RG-2 (S20-S26). A profile
 * whose reticula_start_date OR reticula_end_date is NULL must not generate
 * a refrend on any path (bulk, single, advance).
 */
class GenerateMonthlyRefrendsServiceReticulaMissingTest extends TestCase
{
    use RefreshDatabase;

    private const MSG_MISSING = 'El becario no tiene fechas de retícula cargadas. Cárgalas en la sección Retícula de su perfil.';

    private GenerateMonthlyRefrendsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $this->service = $this->app->make(GenerateMonthlyRefrendsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeProfile(array $overrides = [], bool $missing = false, bool $configPending = false): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        $factory = ScholarshipProfile::factory();
        if ($missing) {
            $factory = $factory->reticulaMissing();
        }
        if ($configPending) {
            $factory = $factory->configPending();
        }

        return $factory->create(array_merge([
            'user_id'        => $user->id,
            'monthly_amount' => 2000,
            'monto_apoyo'    => 0,
        ], $overrides));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public function missingDatesProvider(): array
    {
        return [
            'both null'  => [['reticula_start_date' => null, 'reticula_end_date' => null]],
            'start only' => [['reticula_start_date' => '2026-01-01', 'reticula_end_date' => null]],
            'end only'   => [['reticula_start_date' => null, 'reticula_end_date' => '2026-12-31']],
        ];
    }

    /** @test */
    public function bulk_skips_a_profile_without_dates_and_reports_it(): void
    {
        $profile = $this->makeProfile([], true);
        $profile->user->update(['first_name' => 'Ana', 'last_name' => 'SinReticula']);

        Log::shouldReceive('error')->never();

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['skipped_reticula_missing']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['skipped_out_of_reticula']);
        $this->assertSame([
            'user_id' => $profile->user_id,
            'name'    => 'Ana SinReticula',
            'reason'  => self::MSG_MISSING,
            'kind'    => 'skipped_reticula_missing',
        ], $stats['failures'][0]);
        $this->assertSame(0, ScholarshipRefrend::count());
    }

    /** @test */
    public function bulk_always_reports_the_key_with_zero_default(): void
    {
        $this->makeProfile();

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertArrayHasKey('skipped_reticula_missing', $stats);
        $this->assertSame(0, $stats['skipped_reticula_missing']);
        $this->assertSame(1, $stats['created']);
    }

    /**
     * @test
     * @dataProvider missingDatesProvider
     */
    public function bulk_counts_every_missing_date_variant(array $dates): void
    {
        $this->makeProfile($dates);
        $this->makeProfile();

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['skipped_reticula_missing']);
        $this->assertSame(0, $stats['errors']);
    }

    /**
     * @test
     * @dataProvider missingDatesProvider
     */
    public function generate_for_user_throws_and_creates_nothing(array $dates): void
    {
        $profile = $this->makeProfile($dates);

        try {
            $this->service->generateForUser($profile, 2026, 9);
            $this->fail('Expected ScholarshipReticulaMissingException.');
        } catch (ScholarshipReticulaMissingException $e) {
            $this->assertSame(self::MSG_MISSING, $e->getMessage());
            $this->assertSame('SCHOLARSHIP_RETICULA_MISSING', ScholarshipReticulaMissingException::CODE);
            $this->assertInstanceOf(\DomainException::class, $e);
        }

        $this->assertSame(0, ScholarshipRefrend::count());
    }

    /**
     * @test
     * @dataProvider missingDatesProvider
     */
    public function generate_future_for_advance_throws_and_creates_nothing(array $dates): void
    {
        $profile = $this->makeProfile($dates);

        try {
            $this->service->generateFutureForAdvance($profile, 2026, 12);
            $this->fail('Expected ScholarshipReticulaMissingException.');
        } catch (ScholarshipReticulaMissingException $e) {
            $this->assertSame(self::MSG_MISSING, $e->getMessage());
        }

        $this->assertSame(0, ScholarshipRefrend::count());
    }

    /** @test */
    public function record_advance_payment_action_rolls_back_without_dates(): void
    {
        $admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($admin);
        $profile = $this->makeProfile([], true);
        $origin  = ScholarshipRefrend::create([
            'user_id'                      => $profile->user_id,
            'period_year'                  => 2026,
            'period_month'                 => 9,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => 'IU',
        ]);

        try {
            $this->app->make(RecordAdvancePaymentAction::class)->execute($origin, [
                'months' => [['year' => 2026, 'month' => 12]],
            ], $admin->id);
            $this->fail('Expected ScholarshipReticulaMissingException.');
        } catch (ScholarshipReticulaMissingException $e) {
            $this->assertSame(self::MSG_MISSING, $e->getMessage());
        }

        $this->assertSame(1, ScholarshipRefrend::count());
        $this->assertSame(0, ScholarshipAdvancePayment::count());
    }

    /** @test */
    public function with_both_dates_the_window_is_still_enforced_and_creation_works(): void
    {
        $inside = $this->makeProfile(['reticula_start_date' => '2026-01-01', 'reticula_end_date' => '2026-12-31']);
        $this->assertNotNull($this->service->generateForUser($inside, 2026, 9));

        $outside = $this->makeProfile(['reticula_start_date' => '2026-10-01', 'reticula_end_date' => '2027-06-30']);
        $this->expectException(ReticulaPeriodOutOfRangeException::class);
        $this->service->generateForUser($outside, 2026, 9);
    }

    /** @test */
    public function bulk_keeps_classifying_out_of_window_as_out_of_reticula(): void
    {
        $this->makeProfile(['reticula_start_date' => '2026-10-01', 'reticula_end_date' => '2027-06-30']);

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertSame(1, $stats['skipped_out_of_reticula']);
        $this->assertSame(0, $stats['skipped_reticula_missing']);
    }

    /** @test */
    public function automatic_egreso_refrend_is_still_created_two_months_after_reticula_end(): void
    {
        Carbon::setTestNow(Carbon::create(2027, 3, 15));
        $profile = $this->makeProfile(['reticula_start_date' => '2026-01-01', 'reticula_end_date' => '2026-12-31']);

        $stats = $this->service->generateForPeriod(2027, 2, 'MERIDA');

        $this->assertSame(1, $stats['created']);
        $this->assertSame(
            1,
            ScholarshipRefrend::where('user_id', $profile->user_id)
                ->where('resolution_type', ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA)->count()
        );
    }

    /** @test */
    public function config_pending_takes_precedence_over_missing_dates(): void
    {
        $profile = $this->makeProfile([], true, true);

        $this->expectException(ScholarshipConfigPendingException::class);
        $this->service->generateForUser($profile, 2026, 9);
    }

    /** @test */
    public function bulk_counts_config_pending_and_missing_dates_exclusively(): void
    {
        $this->makeProfile([], true, true);

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertSame(1, $stats['skipped_config_pending']);
        $this->assertSame(0, $stats['skipped_reticula_missing']);
    }

    /** @test */
    public function a_closed_cycle_takes_precedence_over_missing_dates(): void
    {
        $profile = $this->makeProfile(['cycle_closed_period' => '2026-06-01'], true);

        $this->expectException(ScholarshipCycleClosedException::class);
        $this->service->generateForUser($profile, 2026, 9);
    }
}
