<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordAdvancePaymentAction;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordAdvancePaymentActionTest extends TestCase
{
    use RefreshDatabase;

    private RecordAdvancePaymentAction $action;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(RecordAdvancePaymentAction::class);
        $this->admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);

        Carbon::setTestNow(Carbon::create(2026, 9, 15));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
            'reticula_start_date' => null,
            'reticula_end_date'   => null,
            'payment_start_date'  => now()->toDateString(),
        ], $overrides));
    }

    private function makeOriginRefrend(int $userId, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
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
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    // ── Validation matrix ────────────────────────────────────────────────────

    /** @test */
    public function empty_months_array_is_rejected(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        $this->expectException(\DomainException::class);
        $this->action->execute($origin, ['months' => []], $this->admin->id);
    }

    /** @test */
    public function a_request_exceeding_the_cap_is_rejected_before_creating_any_row(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        try {
            $this->action->execute($origin, [
                'months' => [
                    ['year' => 2026, 'month' => 10],
                    ['year' => 2026, 'month' => 11],
                    ['year' => 2026, 'month' => 12],
                    ['year' => 2027, 'month' => 1],
                ],
            ], $this->admin->id);
            $this->fail('Expected a DomainException for exceeding MAX_ADVANCED_MONTHS.');
        } catch (\DomainException $e) {
            // expected
        }

        $this->assertSame(0, ScholarshipAdvancePayment::count());
        $this->assertSame(0, ScholarshipAdvancePaymentMonth::count());
        $this->assertTrue(
            ScholarshipRefrend::where('user_id', $profile->user_id)
                ->where('period_year', 2026)
                ->where('period_month', 10)
                ->doesntExist(),
            'No future refrend should be created when the request is rejected up front.'
        );
    }

    /** @test */
    public function duplicate_month_within_one_request_is_rejected(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        $this->expectException(\DomainException::class);
        $this->action->execute($origin, [
            'months' => [
                ['year' => 2026, 'month' => 12],
                ['year' => 2026, 'month' => 12],
            ],
        ], $this->admin->id);
    }

    /** @test */
    public function a_month_not_strictly_after_the_origin_period_is_rejected(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id, ['period_year' => 2026, 'period_month' => 9]);

        $this->expectException(\DomainException::class);
        $this->action->execute($origin, [
            'months' => [
                ['year' => 2026, 'month' => 9], // same as origin — not future
            ],
        ], $this->admin->id);
    }

    /** @test */
    public function a_month_that_already_has_a_real_refrend_is_rejected(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        // A real (already-generated) NORMAL refrend for 2026-12 exists.
        ScholarshipRefrend::create([
            'user_id'       => $profile->user_id,
            'period_year'   => 2026,
            'period_month'  => 12,
            'refrend_type'  => RefrendType::NORMAL->value,
            'base_amount'   => 1000,
            'final_amount'  => 1000,
            'snapshot_name' => 'Test Becario',
        ]);

        $this->expectException(\DomainException::class);
        $this->action->execute($origin, [
            'months' => [['year' => 2026, 'month' => 12]],
        ], $this->admin->id);
    }

    /** @test */
    public function a_month_already_claimed_by_another_advance_batch_is_rejected(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        // First batch claims 2026-12.
        $this->action->execute($origin, [
            'months' => [['year' => 2026, 'month' => 12]],
        ], $this->admin->id);

        // A second origin refrend (e.g. October's) tries to claim the same
        // 2026-12 period again.
        $secondOrigin = $this->makeOriginRefrend($profile->user_id, ['period_month' => 10]);

        $this->expectException(\DomainException::class);
        $this->action->execute($secondOrigin, [
            'months' => [['year' => 2026, 'month' => 12]],
        ], $this->admin->id);
    }

    // ── Happy paths ──────────────────────────────────────────────────────────

    /** @test */
    public function a_valid_one_month_request_creates_header_child_and_future_refrend(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        $header = $this->action->execute($origin, [
            'months' => [['year' => 2026, 'month' => 12]],
            'cause'  => 'Viaje académico',
        ], $this->admin->id);

        $this->assertInstanceOf(ScholarshipAdvancePayment::class, $header);
        $this->assertSame($profile->user_id, $header->user_id);
        $this->assertSame($origin->id, $header->origin_refrend_id);
        $this->assertSame(2026, $header->origin_period_year);
        $this->assertSame(9, $header->origin_period_month);
        $this->assertSame(1, $header->months_count);
        $this->assertSame($this->admin->id, $header->created_by_id);
        $this->assertSame('Viaje académico', $header->cause);

        $this->assertSame(1, ScholarshipAdvancePaymentMonth::where('advance_payment_id', $header->id)->count());
        $child = ScholarshipAdvancePaymentMonth::where('advance_payment_id', $header->id)->first();
        $this->assertSame(2026, $child->period_year);
        $this->assertSame(12, $child->period_month);
        $this->assertSame('PENDING', $child->status);

        $futureRefrend = ScholarshipRefrend::find($child->refrend_id);
        $this->assertNotNull($futureRefrend);
        $this->assertSame(RefrendType::NORMAL, $futureRefrend->refrend_type);
        $this->assertSame('DRAFT', $futureRefrend->workflow_status);
        $this->assertNull($futureRefrend->resolution_type);
        $this->assertSame('ADVANCE_PAYMENT', $futureRefrend->created_via);

        $this->assertSame($header->total_amount, $child->amount);

        $origin->refresh();
        $this->assertSame($header->total_amount, $origin->advance_payment_amount);
    }

    /** @test */
    public function a_valid_two_month_request_creates_two_children_and_two_future_refrends(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        $header = $this->action->execute($origin, [
            'months' => [
                ['year' => 2026, 'month' => 11],
                ['year' => 2026, 'month' => 12],
            ],
        ], $this->admin->id);

        $this->assertSame(2, $header->months_count);
        $this->assertSame(2, ScholarshipAdvancePaymentMonth::where('advance_payment_id', $header->id)->count());
        $this->assertSame(2, ScholarshipRefrend::where('created_via', 'ADVANCE_PAYMENT')->count());
    }

    /** @test */
    public function a_valid_three_month_request_is_accepted_at_the_cap(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        $header = $this->action->execute($origin, [
            'months' => [
                ['year' => 2026, 'month' => 10],
                ['year' => 2026, 'month' => 11],
                ['year' => 2026, 'month' => 12],
            ],
        ], $this->admin->id);

        $this->assertSame(3, $header->months_count);
        $this->assertSame(RecordAdvancePaymentAction::MAX_ADVANCED_MONTHS, $header->months_count);
    }

    // ── Transactional integrity ──────────────────────────────────────────────

    /** @test */
    public function a_mid_batch_failure_rolls_back_everything(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        // The second requested month (2026-12) already has a real refrend,
        // so it must fail mid-batch — nothing from the first month
        // (2026-11) should survive the rolled-back transaction.
        ScholarshipRefrend::create([
            'user_id'       => $profile->user_id,
            'period_year'   => 2026,
            'period_month'  => 12,
            'refrend_type'  => RefrendType::NORMAL->value,
            'base_amount'   => 1000,
            'final_amount'  => 1000,
            'snapshot_name' => 'Test Becario',
        ]);

        try {
            $this->action->execute($origin, [
                'months' => [
                    ['year' => 2026, 'month' => 11],
                    ['year' => 2026, 'month' => 12],
                ],
            ], $this->admin->id);
            $this->fail('Expected a DomainException for the conflicting second month.');
        } catch (\DomainException $e) {
            // expected
        }

        $this->assertSame(0, ScholarshipAdvancePayment::count());
        $this->assertSame(0, ScholarshipAdvancePaymentMonth::count());
        $this->assertTrue(
            ScholarshipRefrend::where('user_id', $profile->user_id)
                ->where('period_year', 2026)
                ->where('period_month', 11)
                ->doesntExist(),
            'The first (valid) month must be rolled back when a later month in the same batch fails.'
        );
        $origin->refresh();
        $this->assertSame('0.00', $origin->advance_payment_amount);
    }

    /** @test */
    public function a_genuine_race_still_fails_cleanly_at_the_db_layer_even_when_the_app_level_precheck_passes(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        // Simulate a concurrent request winning the race: right as this
        // action is about to insert its own child row for 2026-12, another
        // process's claim for the exact same (user, period) lands first —
        // AFTER this action's own app-level pre-check already passed.
        // scholarship_advance_payment_months.unique(user_id, period_year,
        // period_month) (PR2) must be the final backstop.
        $raceListener = ScholarshipAdvancePaymentMonth::creating(function ($model) use ($profile) {
            if ((int) $model->period_year === 2026 && (int) $model->period_month === 12) {
                $competingRefrend = ScholarshipRefrend::create([
                    'user_id'       => $profile->user_id,
                    'period_year'   => 2026,
                    'period_month'  => 12,
                    // Different refrend_type so the race is isolated to the
                    // advance-payment-months constraint, not
                    // scholarship_refrends' own period-uniqueness.
                    'refrend_type'  => RefrendType::RETENCION->value,
                    'base_amount'   => 100,
                    'final_amount'  => 100,
                    'snapshot_name' => 'Racing Refrend',
                ]);
                $competingHeader = ScholarshipAdvancePayment::create([
                    'user_id'             => $profile->user_id,
                    'origin_refrend_id'   => $competingRefrend->id,
                    'origin_period_year'  => 2026,
                    'origin_period_month' => 12,
                    'months_count'        => 1,
                    'total_amount'        => '100.00',
                    'status'              => 'ACTIVE',
                ]);
                DB::table('scholarship_advance_payment_months')->insert([
                    'advance_payment_id' => $competingHeader->id,
                    'user_id'            => $profile->user_id,
                    'period_year'        => 2026,
                    'period_month'       => 12,
                    'amount'             => '100.00',
                    'refrend_id'         => $competingRefrend->id,
                    'status'             => 'PENDING',
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }
        });

        try {
            $this->expectException(QueryException::class);
            $this->action->execute($origin, [
                'months' => [['year' => 2026, 'month' => 12]],
            ], $this->admin->id);
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.creating: ' . ScholarshipAdvancePaymentMonth::class);
        }
    }
}
