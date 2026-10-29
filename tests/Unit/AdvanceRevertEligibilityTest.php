<?php

namespace Tests\Unit;

use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Exceptions\AdvancePaymentException;
use App\Models\ScholarshipAdjustment;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\TelmexCoverage;
use App\Models\User;
use App\Services\Scholarship\AdvanceRevertEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S2 (spec R3/S5/S6/S2b/S13b): the single
 * revert-eligibility predicate. Evaluation order is part of the contract:
 * NOT_FOUND, ADVANCE_NOT_FOUND, ADVANCE_CYCLE_CLOSED, ADVANCE_ORIGIN_PAID,
 * ADVANCE_ORIGIN_LOCKED, ADVANCE_MONTH_SETTLED, ADVANCE_FUTURE_TELMEX_COVERED,
 * ADVANCE_FUTURE_NOT_PRISTINE.
 */
class AdvanceRevertEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private AdvanceRevertEligibility $eligibility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eligibility = $this->app->make(AdvanceRevertEligibility::class);
    }

    /**
     * @param array<string, mixed> $originOverrides
     * @return array{user: User, origin: ScholarshipRefrend, header: ScholarshipAdvancePayment, futures: array<int, ScholarshipRefrend>, months: array<int, ScholarshipAdvancePaymentMonth>, profile: ScholarshipProfile}
     */
    private function makeAdvance(array $originOverrides = [], int $monthsCount = 2): array
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $profile = ScholarshipProfile::create([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => '2026-01-01',
            'config_set_at'       => now(),
        ]);

        $origin = $this->makeRefrend($user->id, 2026, 9, $originOverrides);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => $monthsCount,
            'total_amount'        => 1000.00 * $monthsCount,
            'status'              => 'ACTIVE',
        ]);

        $futures = [];
        $months  = [];
        for ($i = 1; $i <= $monthsCount; $i++) {
            $month    = 9 + $i;
            $future   = $this->makeRefrend($user->id, 2026, $month, ['created_via' => 'ADVANCE_PAYMENT']);
            $futures[] = $future;
            $months[]  = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id,
                'user_id'            => $user->id,
                'period_year'        => 2026,
                'period_month'       => $month,
                'amount'             => 1000.00,
                'refrend_id'         => $future->id,
                'status'             => 'PENDING',
            ]);
        }

        return compact('user', 'origin', 'header', 'futures', 'months', 'profile');
    }

    /** @param array<string, mixed> $overrides */
    private function makeRefrend(int $userId, int $year, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => $year,
            'period_month'                 => $month,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function assertBlock(?string $expected, ScholarshipRefrend $origin): void
    {
        $this->assertSame($expected, $this->eligibility->evaluate($origin->id));
    }

    /** @test */
    public function a_pristine_advance_is_eligible(): void
    {
        $f = $this->makeAdvance();

        $this->assertBlock(null, $f['origin']);
    }

    /** @test */
    public function an_unknown_refrend_is_not_found(): void
    {
        $this->assertSame(AdvancePaymentException::NOT_FOUND, $this->eligibility->evaluate(999999));
    }

    /** @test */
    public function an_origin_without_a_header_reports_advance_not_found(): void
    {
        $user   = User::factory()->create();
        $origin = $this->makeRefrend($user->id, 2026, 9);

        $this->assertBlock(AdvancePaymentException::ADVANCE_NOT_FOUND, $origin);
    }

    /**
     * @test
     * @dataProvider cycleClosedHeaderStatuses
     */
    public function a_non_active_header_reports_cycle_closed(string $status): void
    {
        $f = $this->makeAdvance();
        $f['header']->update(['status' => $status]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_CYCLE_CLOSED, $f['origin']);
    }

    public function cycleClosedHeaderStatuses(): array
    {
        return [['CANCELLED'], ['PARTIALLY_CANCELLED']];
    }

    /** @test */
    public function a_cancelled_month_reports_cycle_closed(): void
    {
        $f = $this->makeAdvance();
        $f['months'][1]->update(['status' => 'CANCELLED']);

        $this->assertBlock(AdvancePaymentException::ADVANCE_CYCLE_CLOSED, $f['origin']);
    }

    /** @test */
    public function a_closed_user_cycle_reports_cycle_closed(): void
    {
        $f = $this->makeAdvance();
        $f['profile']->update(['cycle_closed_period' => '2026-09-01']);

        $this->assertBlock(AdvancePaymentException::ADVANCE_CYCLE_CLOSED, $f['origin']);
    }

    /** @test */
    public function cycle_closed_takes_precedence_over_paid_and_locked(): void
    {
        $f = $this->makeAdvance(['status' => 'PAID', 'workflow_status' => 'CLOSED', 'locked_at' => now()]);
        $f['header']->update(['status' => 'CANCELLED']);

        $this->assertBlock(AdvancePaymentException::ADVANCE_CYCLE_CLOSED, $f['origin']);
    }

    /** @test */
    public function a_paid_status_origin_reports_origin_paid(): void
    {
        $f = $this->makeAdvance(['status' => RefrendStatus::PAID->value]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_ORIGIN_PAID, $f['origin']);
    }

    /** @test */
    public function a_cheque_paid_origin_reports_origin_paid(): void
    {
        $f = $this->makeAdvance(['paid_payment_method' => PaymentMethod::CHEQUE->value]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_ORIGIN_PAID, $f['origin']);
    }

    /** @test */
    public function a_batched_origin_reports_origin_paid(): void
    {
        $f     = $this->makeAdvance();
        $batch = ScholarshipPaymentBatch::create([
            'campus'          => 'MERIDA',
            'period_year'     => 2026,
            'period_month'    => 9,
            'batch_type'      => 'NORMAL',
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => $f['user']->id,
            'processed_at'    => now(),
        ]);
        $f['origin']->update(['payment_batch_id' => $batch->id]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_ORIGIN_PAID, $f['origin']);
    }

    /** @test */
    public function paid_takes_precedence_over_locked(): void
    {
        $f = $this->makeAdvance([
            'status'          => RefrendStatus::PAID->value,
            'workflow_status' => 'CLOSED',
            'locked_at'       => now(),
        ]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_ORIGIN_PAID, $f['origin']);
    }

    /** @test */
    public function a_closed_origin_reports_origin_locked(): void
    {
        $f = $this->makeAdvance(['workflow_status' => 'CLOSED']);

        $this->assertBlock(AdvancePaymentException::ADVANCE_ORIGIN_LOCKED, $f['origin']);
    }

    /** @test */
    public function a_locked_at_origin_reports_origin_locked(): void
    {
        $f = $this->makeAdvance(['locked_at' => now()]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_ORIGIN_LOCKED, $f['origin']);
    }

    /**
     * @test
     * @dataProvider settledMonthStatuses
     */
    public function a_non_pending_month_reports_month_settled(string $status): void
    {
        $f = $this->makeAdvance();
        $f['months'][0]->update(['status' => $status]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_MONTH_SETTLED, $f['origin']);
    }

    public function settledMonthStatuses(): array
    {
        return [['REACHED'], ['OVERRIDDEN']];
    }

    /** @test */
    public function month_settled_takes_precedence_over_future_problems(): void
    {
        $f = $this->makeAdvance();
        $f['months'][0]->update(['status' => 'REACHED']);
        $f['futures'][1]->update(['resolution_type' => 'SIN_ESPECIFICAR']);

        $this->assertBlock(AdvancePaymentException::ADVANCE_MONTH_SETTLED, $f['origin']);
    }

    /** @test */
    public function a_telmex_covered_future_reports_telmex_covered_before_not_pristine(): void
    {
        $f        = $this->makeAdvance();
        $coverage = TelmexCoverage::create([
            'user_id'                        => $f['user']->id,
            'scholarship_type_at_activation' => ScholarshipType::TELMEX->value,
            'start_period'                   => '2026-01-01',
            'status'                         => 'ACTIVA',
        ]);
        $f['futures'][1]->update(['snapshot_telmex_coverage_id' => $coverage->id]);
        $f['futures'][0]->update(['resolution_type' => 'SIN_ESPECIFICAR']);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_TELMEX_COVERED, $f['origin']);
    }

    /**
     * @test
     * @dataProvider dirtyFutureAttributes
     */
    public function a_future_with_activity_reports_not_pristine(array $attributes): void
    {
        $f = $this->makeAdvance();
        $f['futures'][1]->update($attributes);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE, $f['origin']);
    }

    public function dirtyFutureAttributes(): array
    {
        return [
            'resolution'         => [['resolution_type' => 'SIN_ESPECIFICAR']],
            'resolution cause'   => [['resolution_cause' => 'OTRO']],
            'withholding mode'   => [['withholding_mode' => 'PERCENT']],
            'atencion approval'  => [['atencion_reviewed_at' => '2026-10-01 10:00:00']],
            'pedagogia approval' => [['pedagogia_reviewed_at' => '2026-10-01 10:00:00']],
            'listo para pago'    => [['workflow_status' => 'LISTO_PARA_PAGO']],
            'closed workflow'    => [['workflow_status' => 'CLOSED']],
            'locked'             => [['locked_at' => '2026-10-01 10:00:00']],
            'paid status'        => [['status' => 'PAID']],
            'cheque payment'     => [['paid_payment_method' => 'CHEQUE']],
            'notification'       => [['notified_at' => '2026-10-01 10:00:00']],
        ];
    }

    /** @test */
    public function a_future_with_a_batch_reports_not_pristine(): void
    {
        $f     = $this->makeAdvance();
        $batch = ScholarshipPaymentBatch::create([
            'campus'          => 'MERIDA',
            'period_year'     => 2026,
            'period_month'    => 10,
            'batch_type'      => 'NORMAL',
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => $f['user']->id,
            'processed_at'    => now(),
        ]);
        $f['futures'][0]->update(['payment_batch_id' => $batch->id]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE, $f['origin']);
    }

    /** @test */
    public function a_future_with_a_manual_incident_reports_not_pristine(): void
    {
        $f = $this->makeAdvance();
        $f['futures'][0]->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'OTRO',
            'description'       => 'manual',
            'priority'          => 'LOW',
            'created_by_id'     => $f['user']->id,
        ]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE, $f['origin']);
    }

    /** @test */
    public function a_human_filed_profile_discount_incident_is_not_the_automatic_one(): void
    {
        $f = $this->makeAdvance();
        $f['futures'][0]->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'DESCUENTO_PERFIL',
            'description'       => 'filed by a person',
            'priority'          => 'LOW',
            'created_by_id'     => $f['user']->id,
        ]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE, $f['origin']);
    }

    /** @test */
    public function an_automatic_non_profile_incident_is_not_tolerated(): void
    {
        $f = $this->makeAdvance();
        $f['futures'][0]->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'OTRO',
            'description'       => 'auto but another type',
            'priority'          => 'LOW',
            'created_by_id'     => null,
        ]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE, $f['origin']);
    }

    /** @test */
    public function only_the_automatic_profile_discount_incident_is_tolerated(): void
    {
        $f = $this->makeAdvance();
        $f['futures'][0]->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'DESCUENTO_PERFIL',
            'description'       => 'Descuento de perfil.',
            'priority'          => 'LOW',
            'created_by_id'     => null,
        ]);

        $this->assertBlock(null, $f['origin']);
    }

    /** @test */
    public function automatic_attendance_discounts_on_a_future_are_tolerated(): void
    {
        $f = $this->makeAdvance();
        $f['futures'][0]->discounts()->create([
            'discount_type'       => 'RETARDOS',
            'discount_percentage' => 10,
            'description'         => 'auto',
        ]);
        $f['futures'][1]->discounts()->create([
            'discount_type'       => 'FALTA_INJUSTIFICADA',
            'discount_percentage' => 10,
            'description'         => 'auto',
        ]);

        $this->assertBlock(null, $f['origin']);
    }

    /** @test */
    public function a_future_adjustment_reports_not_pristine(): void
    {
        $f = $this->makeAdvance();
        ScholarshipAdjustment::create([
            'scholarship_refrend_id' => $f['futures'][0]->id,
            'adjustment_type'        => 'CREDIT',
            'amount'                 => 100,
            'reason'                 => 'manual adjustment',
            'applied_by_id'          => $f['user']->id,
        ]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE, $f['origin']);
    }

    /** @test */
    public function a_future_withholding_reports_not_pristine(): void
    {
        $f = $this->makeAdvance();
        ScholarshipWithholding::create([
            'user_id'           => $f['user']->id,
            'origin_refrend_id' => $f['futures'][0]->id,
            'period_year'       => 2026,
            'period_month'      => 10,
            'withheld_amount'   => 100,
            'status'            => 'PENDING',
        ]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE, $f['origin']);
    }

    /** @test */
    public function a_voided_withholding_payment_applied_to_a_future_reports_not_pristine(): void
    {
        $f           = $this->makeAdvance();
        $withholding = ScholarshipWithholding::create([
            'user_id'           => $f['user']->id,
            'origin_refrend_id' => $f['origin']->id,
            'period_year'       => 2026,
            'period_month'      => 9,
            'withheld_amount'   => 100,
            'status'            => 'PENDING',
        ]);
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $withholding->id,
            'applied_refrend_id' => $f['futures'][0]->id,
            'amount'             => 50,
            'is_voided'          => true,
        ]);

        $this->assertBlock(AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE, $f['origin']);
    }

    /** @test */
    public function origin_incidents_resolution_and_discounts_never_block(): void
    {
        $f = $this->makeAdvance([
            'workflow_status'  => 'CON_INCIDENCIA',
            'resolution_type'  => 'SIN_ESPECIFICAR',
            'resolution_notes' => 'staff notes',
        ]);
        $f['origin']->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'OTRO',
            'description'       => 'manual on origin',
            'priority'          => 'LOW',
            'created_by_id'     => $f['user']->id,
        ]);
        $f['origin']->discounts()->create([
            'discount_type'       => 'RETARDOS',
            'discount_percentage' => 10,
            'description'         => 'auto',
        ]);

        $this->assertBlock(null, $f['origin']);
    }

    /** @test */
    public function is_origin_paid_covers_status_paid_cheque_and_batch(): void
    {
        $plain = new ScholarshipRefrend();
        $plain->status = RefrendStatus::DRAFT;
        $this->assertFalse(AdvanceRevertEligibility::isOriginPaid($plain));

        $paid = new ScholarshipRefrend();
        $paid->status = RefrendStatus::PAID;
        $this->assertTrue(AdvanceRevertEligibility::isOriginPaid($paid));

        $cheque = new ScholarshipRefrend();
        $cheque->status              = RefrendStatus::DRAFT;
        $cheque->paid_payment_method = PaymentMethod::CHEQUE;
        $this->assertTrue(AdvanceRevertEligibility::isOriginPaid($cheque));

        $batched = new ScholarshipRefrend();
        $batched->status           = RefrendStatus::DRAFT;
        $batched->payment_batch_id = 7;
        $this->assertTrue(AdvanceRevertEligibility::isOriginPaid($batched));
    }

    /** @test */
    public function assert_eligible_throws_the_typed_exception_with_code_and_status(): void
    {
        $f = $this->makeAdvance();
        $f['header']->update(['status' => 'CANCELLED']);

        try {
            $this->eligibility->assertEligible($f['origin']->id);
            $this->fail('Expected AdvancePaymentException.');
        } catch (AdvancePaymentException $e) {
            $this->assertSame(AdvancePaymentException::ADVANCE_CYCLE_CLOSED, $e->code());
            $this->assertSame(409, $e->httpStatus());
        }

        $this->expectException(AdvancePaymentException::class);
        $this->eligibility->assertEligible(999999);
    }

    /** @test */
    public function assert_eligible_passes_silently_for_an_eligible_advance(): void
    {
        $f = $this->makeAdvance();

        $this->eligibility->assertEligible($f['origin']->id);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function evaluate_many_matches_single_evaluation_and_uses_constant_queries(): void
    {
        $first  = $this->makeAdvance();
        $second = $this->makeAdvance(['status' => 'PAID']);
        $third  = $this->makeAdvance();
        $third['futures'][0]->update(['resolution_type' => 'SIN_ESPECIFICAR']);

        $ids = [$first['origin']->id, $second['origin']->id, $third['origin']->id];

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $many = $this->eligibility->evaluateMany($ids);
        $manyQueries = $queries;

        $this->assertSame([
            $first['origin']->id  => null,
            $second['origin']->id => AdvancePaymentException::ADVANCE_ORIGIN_PAID,
            $third['origin']->id  => AdvancePaymentException::ADVANCE_FUTURE_NOT_PRISTINE,
        ], $many);

        foreach ($ids as $id) {
            $this->assertSame($many[$id], $this->eligibility->evaluate($id));
        }

        $queries = 0;
        $this->eligibility->evaluateMany([$first['origin']->id]);
        $this->assertSame($queries, $manyQueries, 'Query count must not grow with the number of origins.');
    }
}
