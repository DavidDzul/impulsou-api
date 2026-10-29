<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Exceptions\CycleCutBlockedException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\AdvanceCutPlan;
use App\Services\Scholarship\AdvanceCycleCutCanceller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S4b (spec R12-R14, S22-S31; design A2): the
 * advance cancellation planner/applier used by a manual cycle cut. The cut
 * month IS INCLUDED. Not wired into CloseScholarshipCycleAction yet (S5).
 */
class AdvanceCycleCutCancellerTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'BAJA_DEFINITIVA';

    private AdvanceCycleCutCanceller $canceller;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canceller = $this->app->make(AdvanceCycleCutCanceller::class);
        $this->actor     = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    /**
     * One advance header for $user: origin refrend at $originMonth (2026) plus
     * one future refrend and month row per $months entry (month => amount, or
     * month => [amount, status]).
     *
     * @param array<int, string|array{0: string, 1: string}> $months
     * @param array<string, mixed> $originOverrides
     * @return array{user: User, origin: ScholarshipRefrend, header: ScholarshipAdvancePayment, refrends: array<int, ScholarshipRefrend>, months: array<int, ScholarshipAdvancePaymentMonth>}
     */
    private function makeAdvance(int $originMonth, array $months, array $originOverrides = [], ?User $user = null, ?ScholarshipRefrend $origin = null): array
    {
        $user   ??= User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $total    = '0.00';
        foreach ($months as $spec) {
            $total = bcadd($total, is_array($spec) ? $spec[0] : $spec, 2);
        }

        $origin ??= $this->makeRefrend($user->id, $originMonth, array_merge(['advance_payment_amount' => $total], $originOverrides));

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => $originMonth,
            'months_count'        => count($months),
            'total_amount'        => $total,
            'status'              => ScholarshipAdvancePayment::STATUS_ACTIVE,
        ]);

        $refrends = [];
        $rows     = [];
        foreach ($months as $month => $spec) {
            [$amount, $status] = is_array($spec) ? $spec : [$spec, ScholarshipAdvancePaymentMonth::STATUS_PENDING];

            $refrends[$month] = $this->makeRefrend($user->id, $month, ['created_via' => 'ADVANCE_PAYMENT']);
            $rows[$month]     = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id,
                'user_id'            => $user->id,
                'period_year'        => 2026,
                'period_month'       => $month,
                'amount'             => $amount,
                'refrend_id'         => $refrends[$month]->id,
                'status'             => $status,
            ]);
        }

        return ['user' => $user, 'origin' => $origin, 'header' => $header, 'refrends' => $refrends, 'months' => $rows];
    }

    /** @param array<string, mixed> $overrides */
    private function makeRefrend(int $userId, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
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

    private function batchFor(User $user): ScholarshipPaymentBatch
    {
        return ScholarshipPaymentBatch::create([
            'campus'          => 'MERIDA',
            'period_year'     => 2026,
            'period_month'    => 3,
            'batch_type'      => 'NORMAL',
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => $user->id,
            'processed_at'    => now(),
        ]);
    }

    /** @return array<string, array{0: string}> */
    public function paidOriginVariants(): array
    {
        return [
            'payment batch'   => ['batch'],
            'cheque'          => ['cheque'],
            'status PAID'     => ['paid'],
        ];
    }

    /**
     * @test
     * @dataProvider paidOriginVariants
     */
    public function a_paid_origin_keeps_its_money_and_records_the_overpayment_including_the_cut_month(string $variant): void
    {
        // Decision 3b / S31: March paid, April + May advanced at 2500.00, baja in April.
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $paid = match ($variant) {
            'batch'  => ['payment_batch_id' => $this->batchFor($user)->id],
            'cheque' => ['paid_payment_method' => 'CHEQUE'],
            'paid'   => ['status' => RefrendStatus::PAID->value],
        };
        $f = $this->makeAdvance(3, [4 => '2500.00', 5 => '2500.00'], $paid, $user);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        foreach ($f['months'] as $month) {
            $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $month->fresh()->status);
        }
        $header = $f['header']->fresh();
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $header->status);
        $this->assertSame('5000.00', $header->cancelled_amount);
        $this->assertSame('5000.00', $header->overpaid_amount);
        $this->assertSame('5000.00', $header->total_amount);
        $this->assertSame(2, $header->months_count);
        $this->assertSame(self::REASON, $header->cancel_reason);
        $this->assertSame($this->actor->id, $header->cancelled_by_id);
        $this->assertSame($f['refrends'][4]->id, $header->cancelled_cut_refrend_id);
        $this->assertNotNull($header->cancelled_at);
        // Origin money untouched.
        $this->assertSame('5000.00', $f['origin']->fresh()->advance_payment_amount);

        $this->assertInstanceOf(AdvanceCutPlan::class, $plan);
        $this->assertSame('5000.00', $plan->cancelledAmount);
        $this->assertSame('5000.00', $plan->overpaidAmount);
        $this->assertSame('0.00', $plan->advancePaymentReduction);
        $this->assertTrue($plan->origins[0]['origin_paid']);
    }

    /** @test */
    public function an_unpaid_origin_is_reduced_by_the_cancelled_amount_and_has_no_overpayment(): void
    {
        $f = $this->makeAdvance(3, [4 => '2500.00', 5 => '2500.00']);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $header = $f['header']->fresh();
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $header->status);
        $this->assertSame('5000.00', $header->cancelled_amount);
        $this->assertSame('0.00', $header->overpaid_amount);
        $this->assertSame('0.00', $f['origin']->fresh()->advance_payment_amount);

        $this->assertSame('5000.00', $plan->advancePaymentReduction);
        $this->assertSame('0.00', $plan->overpaidAmount);
        $this->assertFalse($plan->origins[0]['origin_paid']);
    }

    /** @test */
    public function an_unpaid_reduction_leaves_the_remainder_when_the_origin_holds_more(): void
    {
        $f = $this->makeAdvance(3, [4 => '2500.00', 5 => '2500.00'], ['advance_payment_amount' => '7000.00']);

        $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $this->assertSame('2000.00', $f['origin']->fresh()->advance_payment_amount);
    }

    /** @test */
    public function an_unpaid_reduction_is_floored_at_zero(): void
    {
        $f = $this->makeAdvance(3, [4 => '2500.00', 5 => '2500.00'], ['advance_payment_amount' => '3000.00']);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $this->assertSame('0.00', $f['origin']->fresh()->advance_payment_amount);
        $this->assertSame('3000.00', $plan->advancePaymentReduction);
        $this->assertSame('0.00', $plan->overpaidAmount);
        $this->assertSame('5000.00', $plan->cancelledAmount);
    }

    /** @test */
    public function months_before_the_cut_are_untouched_and_the_header_is_partially_cancelled(): void
    {
        // S22: Feb, Mar, Apr advanced; cut in March.
        $f = $this->makeAdvance(1, [2 => '1000.00', 3 => '1500.00', 4 => '2000.00']);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][3], self::REASON, $this->actor->id);

        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_PENDING, $f['months'][2]->fresh()->status);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $f['months'][3]->fresh()->status);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $f['months'][4]->fresh()->status);

        $header = $f['header']->fresh();
        $this->assertSame(ScholarshipAdvancePayment::STATUS_PARTIALLY_CANCELLED, $header->status);
        $this->assertSame('3500.00', $header->cancelled_amount);
        $this->assertSame('4500.00', $header->total_amount);
        $this->assertSame(3, $header->months_count);
        $this->assertSame('1000.00', $f['origin']->fresh()->advance_payment_amount);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_PARTIALLY_CANCELLED, $plan->origins[0]['resulting_header_status']);
    }

    /** @test */
    public function a_header_with_every_month_at_or_after_the_cut_is_fully_cancelled(): void
    {
        // S23 (triangulates the partial case above).
        $f = $this->makeAdvance(3, [4 => '1000.00', 5 => '1000.00']);

        $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $f['header']->fresh()->status);
    }

    /** @test */
    public function a_cut_month_row_already_reached_is_cancelled_and_counted_exactly_once(): void
    {
        // S24: April was already reconciled (cut refrend re-recorded).
        $f = $this->makeAdvance(3, [
            4 => ['2500.00', ScholarshipAdvancePaymentMonth::STATUS_REACHED],
            5 => '2500.00',
        ]);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $f['months'][4]->fresh()->status);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $f['months'][5]->fresh()->status);
        $this->assertSame('5000.00', $f['header']->fresh()->cancelled_amount);
        $this->assertSame('5000.00', $plan->cancelledAmount);
        $this->assertCount(2, $plan->months);
        $this->assertSame(1, collect($plan->months)->where('is_cut_month', true)->count());
    }

    /** @test */
    public function a_cut_month_row_already_overridden_is_cancelled_too(): void
    {
        $f = $this->makeAdvance(3, [
            4 => ['2500.00', ScholarshipAdvancePaymentMonth::STATUS_OVERRIDDEN],
            5 => ['1000.00', ScholarshipAdvancePaymentMonth::STATUS_REACHED],
        ]);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $f['months'][4]->fresh()->status);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $f['months'][5]->fresh()->status);
        $this->assertSame('3500.00', $plan->cancelledAmount);
    }

    /** @test */
    public function when_the_origin_is_the_cut_refrend_its_advance_amount_is_reduced(): void
    {
        // S28
        $user   = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $origin = $this->makeRefrend($user->id, 4, ['advance_payment_amount' => '3000.00']);
        $f      = $this->makeAdvance(4, [5 => '1500.00', 6 => '1500.00'], [], $user, $origin);

        $plan = $this->canceller->cancelAfterCut($origin, self::REASON, $this->actor->id);

        $this->assertSame('0.00', $origin->fresh()->advance_payment_amount);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $f['header']->fresh()->status);
        $this->assertSame('0.00', $f['header']->fresh()->overpaid_amount);
        $this->assertSame('3000.00', $plan->advancePaymentReduction);
        $this->assertFalse(collect($plan->months)->contains('is_cut_month', true));
    }

    /** @test */
    public function when_the_origin_is_after_the_cut_it_is_reduced_and_only_advance_refrends_after_the_cut_are_listed(): void
    {
        // S29: June origin advanced July + August; cut is the earlier April refrend.
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $cut  = $this->makeRefrend($user->id, 4);
        $f    = $this->makeAdvance(6, [7 => '1000.00', 8 => '1000.00'], [], $user);

        $plan = $this->canceller->cancelAfterCut($cut, self::REASON, $this->actor->id);

        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $f['header']->fresh()->status);
        $this->assertSame('0.00', $f['origin']->fresh()->advance_payment_amount);
        $this->assertSame(
            [$f['refrends'][7]->id, $f['refrends'][8]->id],
            $plan->advanceRefrendIds
        );
        $this->assertNotContains($f['origin']->id, $plan->advanceRefrendIds);
    }

    /** @test */
    public function advance_refrend_ids_exclude_the_cut_refrend_itself(): void
    {
        $f = $this->makeAdvance(3, [4 => '1000.00', 5 => '1000.00', 6 => '1000.00']);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $this->assertSame([$f['refrends'][5]->id, $f['refrends'][6]->id], $plan->advanceRefrendIds);
        $this->assertCount(3, $plan->months);
    }

    /** @test */
    public function two_headers_are_handled_independently(): void
    {
        // S30: header A (paid origin Jan) has Mar + Apr; header B (unpaid origin Feb) has May + Jun. Cut Apr.
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $a    = $this->makeAdvance(1, [3 => '1000.00', 4 => '2000.00'], ['paid_payment_method' => 'CHEQUE'], $user);
        $b    = $this->makeAdvance(2, [5 => '3000.00', 6 => '4000.00'], [], $user);

        $plan = $this->canceller->cancelAfterCut($a['refrends'][4], self::REASON, $this->actor->id);

        $headerA = $a['header']->fresh();
        $this->assertSame(ScholarshipAdvancePayment::STATUS_PARTIALLY_CANCELLED, $headerA->status);
        $this->assertSame('2000.00', $headerA->cancelled_amount);
        $this->assertSame('2000.00', $headerA->overpaid_amount);
        $this->assertSame('3000.00', $a['origin']->fresh()->advance_payment_amount);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_PENDING, $a['months'][3]->fresh()->status);

        $headerB = $b['header']->fresh();
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $headerB->status);
        $this->assertSame('7000.00', $headerB->cancelled_amount);
        $this->assertSame('0.00', $headerB->overpaid_amount);
        $this->assertSame('0.00', $b['origin']->fresh()->advance_payment_amount);

        $this->assertSame('9000.00', $plan->cancelledAmount);
        $this->assertSame('2000.00', $plan->overpaidAmount);
        $this->assertSame('7000.00', $plan->advancePaymentReduction);
        $this->assertCount(2, $plan->origins);
    }

    /** @test */
    public function already_cancelled_months_are_ignored(): void
    {
        // S25: an earlier cut cancelled April; only May is cancelled now.
        $f = $this->makeAdvance(3, [
            4 => ['2500.00', ScholarshipAdvancePaymentMonth::STATUS_CANCELLED],
            5 => '1000.00',
        ]);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $this->assertCount(1, $plan->months);
        $this->assertSame($f['months'][5]->id, $plan->months[0]['id']);
        $this->assertSame('1000.00', $plan->cancelledAmount);
        $this->assertSame('1000.00', $f['header']->fresh()->cancelled_amount);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $f['header']->fresh()->status);
    }

    /** @test */
    public function months_of_other_users_are_not_touched(): void
    {
        $mine  = $this->makeAdvance(3, [4 => '1000.00']);
        $other = $this->makeAdvance(3, [4 => '1000.00']);

        $this->canceller->cancelAfterCut($mine['refrends'][4], self::REASON, $this->actor->id);

        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_PENDING, $other['months'][4]->fresh()->status);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $other['header']->fresh()->status);
        $this->assertSame('1000.00', $other['origin']->fresh()->advance_payment_amount);
    }

    /** @test */
    public function amounts_are_summed_without_float_drift(): void
    {
        $f = $this->makeAdvance(1, [2 => '0.10', 3 => '0.20', 4 => '333.33', 5 => '333.33', 6 => '333.33']);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][2], self::REASON, $this->actor->id);

        $this->assertSame('1000.29', $plan->cancelledAmount);
        $this->assertSame('1000.29', $f['header']->fresh()->cancelled_amount);
        $this->assertSame('0.00', $f['origin']->fresh()->advance_payment_amount);
    }

    /** @test */
    public function with_no_advance_months_at_or_after_the_cut_the_plan_is_empty_and_nothing_is_written(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $f    = $this->makeAdvance(1, [2 => '1000.00'], [], $user);
        $cut  = $this->makeRefrend($user->id, 9);

        $plan = $this->canceller->cancelAfterCut($cut, self::REASON, $this->actor->id);

        $this->assertSame([], $plan->months);
        $this->assertSame([], $plan->origins);
        $this->assertSame([], $plan->advanceRefrendIds);
        $this->assertSame('0.00', $plan->cancelledAmount);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $f['header']->fresh()->status);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_PENDING, $f['months'][2]->fresh()->status);
    }

    /** @test */
    public function preview_performs_zero_writes_and_equals_the_plan_of_cancel_after_cut(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $f    = $this->makeAdvance(3, [4 => '2500.00', 5 => '2500.00'], ['payment_batch_id' => $this->batchFor($user)->id], $user);

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $preview = $this->canceller->preview($f['refrends'][4]);

        $this->assertSame([], $writes);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $f['header']->fresh()->status);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_PENDING, $f['months'][4]->fresh()->status);
        $this->assertSame('5000.00', $preview->overpaidAmount);

        $applied = $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);

        $this->assertNotSame([], $writes);
        $this->assertEquals($preview->toArray(), $applied->toArray());
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $f['header']->fresh()->status);
    }

    /** @test */
    public function plan_is_pure_and_performs_no_queries(): void
    {
        $f = $this->makeAdvance(3, [4 => '2500.00', 5 => '2500.00']);

        $loaded = $this->canceller->loadCandidates($f['refrends'][4], false);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $plan = $this->canceller->plan($f['refrends'][4], $loaded['months'], $loaded['headers']);

        $this->assertSame([], $queries);
        $this->assertSame('5000.00', $plan->cancelledAmount);
        $this->assertCount(2, $plan->months);
    }

    /** @test */
    public function a_later_advance_refrend_locked_under_the_lock_blocks_the_cut_and_writes_nothing(): void
    {
        $f = $this->makeAdvance(3, [4 => '1000.00', 5 => '1000.00']);
        $f['refrends'][5]->update(['workflow_status' => 'CLOSED', 'locked_at' => now(), 'locked_by_id' => $this->actor->id]);

        try {
            $this->canceller->cancelAfterCut($f['refrends'][4], self::REASON, $this->actor->id);
            $this->fail('Expected CycleCutBlockedException');
        } catch (CycleCutBlockedException $e) {
            $this->assertSame([['year' => 2026, 'month' => 5, 'reason' => 'PAID']], $e->payload()['blocking_months']);
        }

        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $f['header']->fresh()->status);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_PENDING, $f['months'][5]->fresh()->status);
        $this->assertSame('2000.00', $f['origin']->fresh()->advance_payment_amount);
    }

    /** @test */
    public function a_later_batched_origin_blocks_the_cut(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $cut  = $this->makeRefrend($user->id, 4);
        $this->makeAdvance(6, [7 => '1000.00'], ['payment_batch_id' => $this->batchFor($user)->id], $user);

        $this->expectException(CycleCutBlockedException::class);

        $this->canceller->cancelAfterCut($cut, self::REASON, $this->actor->id);
    }

    /** @test */
    public function a_locked_cut_refrend_does_not_block_its_own_cancellation(): void
    {
        $f = $this->makeAdvance(3, [4 => '1000.00']);
        $f['refrends'][4]->update(['workflow_status' => 'CLOSED', 'locked_at' => now(), 'locked_by_id' => $this->actor->id]);

        $plan = $this->canceller->cancelAfterCut($f['refrends'][4]->fresh(), self::REASON, $this->actor->id);

        $this->assertSame('1000.00', $plan->cancelledAmount);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $f['header']->fresh()->status);
    }
}
