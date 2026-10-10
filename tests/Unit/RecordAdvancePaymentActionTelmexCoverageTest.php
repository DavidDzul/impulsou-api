<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordAdvancePaymentAction;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-iu, design D6 / decisions-2 dec1: CERT/advance
 * payments are BLOCKED when the origin OR any requested future month is
 * covered. Arrival reconciliation requires a $0 outcome, which would
 * contradict "the covered part is never zeroed".
 */
class RecordAdvancePaymentActionTelmexCoverageTest extends TestCase
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
            'scholarship_type'    => ScholarshipType::TELMEX_IU->value,
            'monthly_amount'      => 1800.00,
            'monto_apoyo'         => 150.00,
            'iu_payment_amount'   => 300.00,
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
            'base_amount'                  => 300.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 300.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::TELMEX_IU->value,
        ], $overrides));
    }

    /** @test */
    public function rejects_when_the_origin_refrend_is_covered(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id, [
            'snapshot_telmex_covered_amount' => 1950.00,
            'snapshot_telmex_coverage_id'    => TelmexCoverage::create([
                'user_id'                        => $profile->user_id,
                'scholarship_type_at_activation'  => ScholarshipType::TELMEX_IU->value,
                'start_period'                    => '2026-09-01',
                'end_period'                      => null,
                'status'                          => 'ACTIVA',
            ])->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->action->execute($origin, ['months' => [['year' => 2026, 'month' => 12]]], $this->admin->id);

        $this->assertSame(0, ScholarshipAdvancePayment::count());
    }

    /** @test */
    public function rejects_when_a_requested_future_month_resolves_to_an_active_coverage(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        // Coverage starts in the future, covering the requested month (12)
        // but NOT the origin's own period (9).
        TelmexCoverage::create([
            'user_id'                        => $profile->user_id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX_IU->value,
            'start_period'                    => '2026-12-01',
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
        ]);

        try {
            $this->action->execute($origin, ['months' => [['year' => 2026, 'month' => 12]]], $this->admin->id);
            $this->fail('Expected a DomainException for an advance into a covered month.');
        } catch (\DomainException $e) {
            // expected
        }

        $this->assertSame(0, ScholarshipAdvancePayment::count());
        $this->assertTrue(
            ScholarshipRefrend::where('user_id', $profile->user_id)
                ->where('period_year', 2026)
                ->where('period_month', 12)
                ->doesntExist(),
            'The future refrend created inside the rejected transaction must be rolled back.'
        );
    }

    /** @test */
    public function allows_an_uncovered_advance_unchanged(): void
    {
        $profile = $this->makeProfile();
        $origin  = $this->makeOriginRefrend($profile->user_id);

        $header = $this->action->execute($origin, [
            'months' => [['year' => 2026, 'month' => 12]],
        ], $this->admin->id);

        $this->assertInstanceOf(ScholarshipAdvancePayment::class, $header);
    }
}
