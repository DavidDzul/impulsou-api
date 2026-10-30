<?php

namespace Tests\Unit;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Enums\WithholdingCancellationReason;
use App\Exceptions\CycleCutBlockedException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/retenciones-pendientes, PR4 (R5.1-R5.4, S4-S6): the automatic egreso
 * (retícula month+2, GENERATION path) cancels the becario's PENDING
 * withholdings (EGRESO_RETICULA) and advance payments (label EGRESO_RETICULA,
 * system actor) inside the same transaction as the $0 CLOSED refrend and the
 * graduation.
 *
 * Timeline: reticula_end_date 2026-07-31 -> egreso administrativo cutoff
 * 2026-09-30, so September 2026 is the egreso month. "Now" is 2026-10-15.
 */
class GenerateMonthlyRefrendsServiceAutoEgresoCancellationTest extends TestCase
{
    use RefreshDatabase;

    private GenerateMonthlyRefrendsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 15));
        $this->service = $this->app->make(GenerateMonthlyRefrendsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function profile(array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        return ScholarshipProfile::create(array_merge([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2026-07-31',
            'payment_start_date'  => '2026-01-01',
            'config_set_at'       => now(),
        ], $overrides));
    }

    private function refrend(ScholarshipProfile $p, int $month, array $extra = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id' => $p->user_id, 'period_year' => 2026, 'period_month' => $month,
            'refrend_type' => RefrendType::NORMAL->value, 'status' => 'DRAFT', 'workflow_status' => 'DRAFT',
            'base_amount' => 1000, 'discount_percentage' => 0, 'discount_amount' => 0, 'final_amount' => 1000,
            'amount_pending_from_previous' => 0, 'snapshot_name' => 'T',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ], $extra));
    }

    private function withholding(ScholarshipProfile $p, int $month, string $status = 'PENDING'): ScholarshipWithholding
    {
        $origin = $this->refrend($p, $month, ['status' => 'WITHHELD']);

        return ScholarshipWithholding::create([
            'user_id' => $p->user_id, 'origin_refrend_id' => $origin->id, 'period_year' => 2026,
            'period_month' => $month, 'withheld_amount' => '100.00',
            'paid_amount' => $status === 'PAID' ? '100.00' : '0.00', 'status' => $status,
        ]);
    }

    /**
     * Header whose origin is the $originMonth refrend and whose months are given
     * as [month => status]. Month refrends are ADVANCE_PAYMENT-born.
     *
     * @param array<int, string> $months
     * @return array{origin: ScholarshipRefrend, header: ScholarshipAdvancePayment, refrends: array<int, ScholarshipRefrend>, months: array<int, ScholarshipAdvancePaymentMonth>}
     */
    private function advance(ScholarshipProfile $p, int $originMonth, array $months, array $originExtra = []): array
    {
        $total  = number_format(1000 * count($months), 2, '.', '');
        $origin = $this->refrend($p, $originMonth, array_merge(['advance_payment_amount' => $total], $originExtra));
        $header = ScholarshipAdvancePayment::create([
            'user_id' => $p->user_id, 'origin_refrend_id' => $origin->id, 'origin_period_year' => 2026,
            'origin_period_month' => $originMonth, 'months_count' => count($months), 'total_amount' => $total,
            'status' => ScholarshipAdvancePayment::STATUS_ACTIVE,
        ]);

        $refrends = $rows = [];
        foreach ($months as $m => $status) {
            $refrends[$m] = $this->refrend($p, $m, ['created_via' => 'ADVANCE_PAYMENT']);
            $rows[$m]     = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id, 'user_id' => $p->user_id, 'period_year' => 2026,
                'period_month' => $m, 'amount' => '1000.00', 'refrend_id' => $refrends[$m]->id, 'status' => $status,
            ]);
        }

        return ['origin' => $origin, 'header' => $header, 'refrends' => $refrends, 'months' => $rows];
    }

    private function pending(): string
    {
        return ScholarshipAdvancePaymentMonth::STATUS_PENDING;
    }

    private function logCount(string $action): int
    {
        return ScholarshipRefrendLog::where('action', $action)->count();
    }

    private function assertCleanEgreso(ScholarshipRefrend $egreso, ScholarshipProfile $p): void
    {
        $this->assertSame('0.00', (string) $egreso->final_amount);
        $this->assertSame('CANCELLED', $egreso->status->value);
        $this->assertSame('CLOSED', $egreso->workflow_status);
        $this->assertNotNull($egreso->locked_at);
        $this->assertSame(ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA, $egreso->resolution_type);
        $this->assertSame('BEC_INACTIVE', $p->user->fresh()->user_type);
    }

    /** @test */
    public function egreso_cancels_all_pending_withholdings_with_the_egreso_reason_and_no_actor(): void
    {
        $p  = $this->profile();
        $w1 = $this->withholding($p, 6);
        $w2 = $this->withholding($p, 7);

        $egreso = $this->service->generateForUser($p, 2026, 9);

        $this->assertCleanEgreso($egreso, $p);
        foreach ([$w1, $w2] as $w) {
            $w->refresh();
            $this->assertSame('CANCELLED', $w->status);
            $this->assertSame(WithholdingCancellationReason::EGRESO_RETICULA, $w->cancellation_reason);
            $this->assertNotNull($w->cancelled_at);
            $this->assertNull($w->cancelled_by_id);
            $this->assertSame('0.00', (string) $w->paid_amount);
        }
        $this->assertSame(2, $this->logCount('WITHHOLDING_CANCELLED'));
        $this->assertSame(0, $this->logCount('ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT'));
        $this->assertTrue(ScholarshipRefrendLog::where('action', 'WITHHOLDING_CANCELLED')
            ->where('scholarship_refrend_id', $w1->origin_refrend_id)->exists());
    }

    /** @test */
    public function egreso_cancels_advance_payments_as_history_with_the_egreso_label(): void
    {
        $p = $this->profile();
        $a = $this->advance($p, 8, [10 => $this->pending(), 11 => $this->pending()]);

        $egreso = $this->service->generateForUser($p, 2026, 9);

        $this->assertCleanEgreso($egreso, $p);

        $header = $a['header']->fresh();
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $header->status);
        $this->assertSame('EGRESO_RETICULA', $header->cancel_reason);
        $this->assertNull($header->cancelled_by_id);
        $this->assertNotNull($header->cancelled_at);
        $this->assertSame($egreso->id, (int) $header->cancelled_cut_refrend_id);
        $this->assertSame('2000.00', (string) $header->cancelled_amount);
        foreach ([10, 11] as $m) {
            $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $a['months'][$m]->fresh()->status);
            // Cancelled as history: the egreso does not close or alter the month refrends.
            $this->assertSame('DRAFT', $a['refrends'][$m]->fresh()->workflow_status);
            $this->assertNull($a['refrends'][$m]->fresh()->locked_at);
        }
        $this->assertSame('0.00', (string) $a['origin']->fresh()->advance_payment_amount);

        $this->assertSame(1, $this->logCount('ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT'));
        $this->assertStringContainsString(
            'EGRESO POR RETÍCULA',
            ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->first()->notes
        );
        $this->assertSame(0, $this->logCount('WITHHOLDING_CANCELLED'));
    }

    /** @test */
    public function egreso_with_withholdings_and_advances_cancels_both_in_one_run(): void
    {
        $p = $this->profile();
        $w = $this->withholding($p, 6);
        $a = $this->advance($p, 8, [10 => $this->pending(), 11 => $this->pending(), 12 => $this->pending()]);

        $egreso = $this->service->generateForUser($p, 2026, 9);

        $this->assertCleanEgreso($egreso, $p);
        $this->assertSame('CANCELLED', $w->fresh()->status);
        $this->assertSame(WithholdingCancellationReason::EGRESO_RETICULA, $w->fresh()->cancellation_reason);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $a['header']->fresh()->status);
        $this->assertSame('3000.00', (string) $a['header']->fresh()->cancelled_amount);
        $this->assertSame(1, $this->logCount('WITHHOLDING_CANCELLED'));
        $this->assertSame(1, $this->logCount('ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT'));
    }

    /** @test */
    public function egreso_without_withholdings_or_advances_keeps_the_previous_behavior(): void
    {
        $p = $this->profile();

        $egreso = $this->service->generateForUser($p, 2026, 9);

        $this->assertCleanEgreso($egreso, $p);
        $this->assertNull($egreso->locked_by_id);
        $this->assertSame(1, ScholarshipRefrendLog::where('scholarship_refrend_id', $egreso->id)->where('action', 'graduated')->count());
        $this->assertSame(0, $this->logCount('WITHHOLDING_CANCELLED'));
        $this->assertSame(0, $this->logCount('ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT'));
        $this->assertSame(0, ScholarshipAdvancePayment::count());
    }

    /** @test */
    public function paid_and_already_cancelled_withholdings_and_consumed_advance_history_are_untouched(): void
    {
        $p    = $this->profile();
        $paid = $this->withholding($p, 5, 'PAID');
        $old  = $this->withholding($p, 6, 'CANCELLED');
        $pend = $this->withholding($p, 7);
        // Header with a consumed month (Aug REACHED, before the anchor) and a future pending one.
        $a = $this->advance($p, 4, [8 => ScholarshipAdvancePaymentMonth::STATUS_REACHED, 10 => $this->pending()], [
            'locked_at' => now()->subMonth(), 'workflow_status' => 'CLOSED', 'status' => 'PAID',
        ]);

        $this->service->generateForUser($p, 2026, 9);

        $this->assertSame('PAID', $paid->fresh()->status);
        $this->assertNull($paid->fresh()->cancellation_reason);
        $this->assertNull($paid->fresh()->cancelled_at);
        $this->assertSame('CANCELLED', $old->fresh()->status);
        $this->assertNull($old->fresh()->cancellation_reason, 'legacy CANCELLED row keeps NULL reason');
        $this->assertNull($old->fresh()->cancelled_at);
        $this->assertSame(WithholdingCancellationReason::EGRESO_RETICULA, $pend->fresh()->cancellation_reason);

        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_REACHED, $a['months'][8]->fresh()->status);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, $a['months'][10]->fresh()->status);
        $header = $a['header']->fresh();
        $this->assertSame(ScholarshipAdvancePayment::STATUS_PARTIALLY_CANCELLED, $header->status);
        $this->assertSame('1000.00', (string) $header->cancelled_amount);
        // Paid (locked) origin: money untouched, the cancelled amount becomes overpaid.
        $this->assertSame('1000.00', (string) $header->overpaid_amount);
        $this->assertSame('2000.00', (string) $a['origin']->fresh()->advance_payment_amount);
        $this->assertSame('CLOSED', $a['origin']->fresh()->workflow_status);
        $this->assertSame('DRAFT', $a['refrends'][8]->fresh()->workflow_status);
    }

    /** @test */
    public function rerunning_generation_for_the_same_and_the_next_period_is_a_no_op(): void
    {
        $p = $this->profile();
        $this->withholding($p, 6);
        $this->advance($p, 8, [10 => $this->pending()]);

        $first = $this->service->generateForUser($p, 2026, 9);
        $this->assertNotNull($first);

        $refrendCount = ScholarshipRefrend::count();
        $wLogs        = $this->logCount('WITHHOLDING_CANCELLED');
        $aLogs        = $this->logCount('ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT');
        $gradLogs     = $this->logCount('graduated');
        $cancelledAt  = ScholarshipWithholding::first()->cancelled_at;

        $sameBulk = $this->service->generateForPeriod(2026, 9, 'MERIDA');
        $this->assertSame(0, $sameBulk['created']);
        $this->assertSame(1, $sameBulk['skipped']);
        $this->assertSame(0, $sameBulk['errors']);
        $this->assertNull($this->service->generateForUser($p, 2026, 9));

        $nextBulk = $this->service->generateForPeriod(2026, 10, 'MERIDA');
        $this->assertSame(0, $nextBulk['created']);
        $this->assertSame(0, $nextBulk['errors']);

        $this->assertSame($refrendCount, ScholarshipRefrend::count());
        $this->assertSame($wLogs, $this->logCount('WITHHOLDING_CANCELLED'));
        $this->assertSame($aLogs, $this->logCount('ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT'));
        $this->assertSame($gradLogs, $this->logCount('graduated'));
        $this->assertEquals($cancelledAt, ScholarshipWithholding::first()->cancelled_at);
        $this->assertSame(1, ScholarshipAdvancePayment::where('status', ScholarshipAdvancePayment::STATUS_CANCELLED)->count());
    }

    /** @test */
    public function the_advance_path_hitting_the_egreso_month_cancels_nothing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15));
        $p = $this->profile();
        $w = $this->withholding($p, 6);
        $a = $this->advance($p, 7, [10 => $this->pending()]);

        $refrend = $this->service->generateFutureForAdvance($p, 2026, 9);

        $this->assertSame(ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA, $refrend->resolution_type);
        $this->assertSame('BEC_ACTIVE', $p->user->fresh()->user_type);
        $this->assertSame('PENDING', $w->fresh()->status);
        $this->assertNull($w->fresh()->cancelled_at);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $a['header']->fresh()->status);
        $this->assertSame($this->pending(), $a['months'][10]->fresh()->status);
        $this->assertSame(0, $this->logCount('WITHHOLDING_CANCELLED'));
        $this->assertSame(0, $this->logCount('ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT'));
    }

    /** @test */
    public function bulk_generation_only_cancels_for_the_becario_that_egresa_and_counts_correctly(): void
    {
        $egresa = $this->profile();
        $otherA = $this->profile(['reticula_end_date' => '2099-12-31']);
        $otherB = $this->profile(['reticula_end_date' => '2099-12-31']);

        $wEgresa = $this->withholding($egresa, 6);
        $wA      = $this->withholding($otherA, 6);
        $wB      = $this->withholding($otherB, 6);
        $aEgresa = $this->advance($egresa, 8, [10 => $this->pending()]);
        $aA      = $this->advance($otherA, 8, [10 => $this->pending()]);

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertSame(3, $stats['created']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame([], $stats['failures']);

        $this->assertSame('CANCELLED', $wEgresa->fresh()->status);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_CANCELLED, $aEgresa['header']->fresh()->status);
        $this->assertSame('BEC_INACTIVE', $egresa->user->fresh()->user_type);

        foreach ([$wA, $wB] as $w) {
            $this->assertSame('PENDING', $w->fresh()->status);
            $this->assertNull($w->fresh()->cancelled_at);
        }
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $aA['header']->fresh()->status);
        $this->assertSame('BEC_ACTIVE', $otherA->user->fresh()->user_type);
        $this->assertSame('BEC_ACTIVE', $otherB->user->fresh()->user_type);
        $this->assertSame(1, $this->logCount('WITHHOLDING_CANCELLED'));
    }

    /** @test */
    public function a_blocked_cancellation_rolls_back_the_whole_egreso_of_that_becario(): void
    {
        $p = $this->profile();
        $w = $this->withholding($p, 6);
        $a = $this->advance($p, 8, [10 => $this->pending(), 11 => $this->pending()]);
        // A later advance month that is already locked blocks the cancellation (anomalous state).
        $a['refrends'][11]->update(['workflow_status' => 'CLOSED', 'locked_at' => now()]);

        try {
            $this->service->generateForUser($p, 2026, 9);
            $this->fail('Expected CycleCutBlockedException');
        } catch (CycleCutBlockedException) {
        }

        $this->assertFalse(ScholarshipRefrend::where('user_id', $p->user_id)->where('period_month', 9)->exists());
        $this->assertSame('BEC_ACTIVE', $p->user->fresh()->user_type);
        $this->assertSame('PENDING', $w->fresh()->status);
        $this->assertNull($w->fresh()->cancelled_at);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $a['header']->fresh()->status);
        $this->assertSame($this->pending(), $a['months'][10]->fresh()->status);
        $this->assertSame('2000.00', (string) $a['origin']->fresh()->advance_payment_amount);
        $this->assertSame(0, $this->logCount('WITHHOLDING_CANCELLED'));
        $this->assertSame(0, $this->logCount('ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT'));
        $this->assertSame(0, $this->logCount('graduated'));
    }

    /** @test */
    public function bulk_generation_counts_a_blocked_egreso_as_an_error_and_keeps_processing_the_rest(): void
    {
        $blocked = $this->profile();
        $healthy = $this->profile(['reticula_end_date' => '2099-12-31']);
        $normal  = $this->profile();

        $wBlocked = $this->withholding($blocked, 6);
        $a        = $this->advance($blocked, 8, [10 => $this->pending(), 11 => $this->pending()]);
        $a['refrends'][11]->update(['workflow_status' => 'CLOSED', 'locked_at' => now()]);
        $wNormal = $this->withholding($normal, 6);

        $stats = $this->service->generateForPeriod(2026, 9, 'MERIDA');

        $this->assertSame(2, $stats['created']);
        $this->assertSame(1, $stats['errors']);
        $this->assertCount(1, $stats['failures']);
        $this->assertSame($blocked->user_id, $stats['failures'][0]['user_id']);
        $this->assertSame('error', $stats['failures'][0]['kind']);

        // The blocked becario is fully rolled back; the others completed normally.
        $this->assertFalse(ScholarshipRefrend::where('user_id', $blocked->user_id)->where('period_month', 9)->exists());
        $this->assertSame('BEC_ACTIVE', $blocked->user->fresh()->user_type);
        $this->assertSame('PENDING', $wBlocked->fresh()->status);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $a['header']->fresh()->status);

        $this->assertSame('CANCELLED', $wNormal->fresh()->status);
        $this->assertSame('BEC_INACTIVE', $normal->user->fresh()->user_type);
        $this->assertTrue(ScholarshipRefrend::where('user_id', $healthy->user_id)->where('period_month', 9)->exists());
        $this->assertSame('BEC_ACTIVE', $healthy->user->fresh()->user_type);
    }
}
