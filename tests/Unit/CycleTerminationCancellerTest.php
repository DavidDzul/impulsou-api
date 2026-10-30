<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Enums\WithholdingCancellationReason;
use App\Exceptions\CycleCutBlockedException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use App\Services\Scholarship\CutAnchor;
use App\Services\Scholarship\CycleTerminationCanceller;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/retenciones-pendientes, PR2 (R1.4, R5.1, R6.1): orchestrates the
 * pending-withholding canceller, the advance canceller and the advance log for
 * the automatic egreso (no refrend closing) and the user deactivation (also
 * closes the refrends born from cancelled advance months).
 */
class CycleTerminationCancellerTest extends TestCase
{
    use RefreshDatabase;

    private CycleTerminationCanceller $canceller;

    private User $actor;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canceller = $this->app->make(CycleTerminationCanceller::class);
        $this->actor     = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->user      = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
    }

    private function refrend(int $month, array $extra = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id' => $this->user->id, 'period_year' => 2026, 'period_month' => $month,
            'status' => 'DRAFT', 'workflow_status' => 'DRAFT', 'base_amount' => 1000,
            'discount_percentage' => 0, 'discount_amount' => 0, 'final_amount' => 1000,
            'amount_pending_from_previous' => 0, 'snapshot_name' => 'T',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ], $extra));
    }

    /** @return array{origin: ScholarshipRefrend, header: ScholarshipAdvancePayment, refrends: array<int, ScholarshipRefrend>, months: array<int, ScholarshipAdvancePaymentMonth>} */
    private function advance(array $months): array
    {
        $origin = $this->refrend(3, ['advance_payment_amount' => number_format(1000 * count($months), 2, '.', '')]);
        $header = ScholarshipAdvancePayment::create([
            'user_id' => $this->user->id, 'origin_refrend_id' => $origin->id, 'origin_period_year' => 2026,
            'origin_period_month' => 3, 'months_count' => count($months),
            'total_amount' => number_format(1000 * count($months), 2, '.', ''),
            'status' => ScholarshipAdvancePayment::STATUS_ACTIVE,
        ]);
        $refrends = $rows = [];
        foreach ($months as $m) {
            $refrends[$m] = $this->refrend($m, ['created_via' => 'ADVANCE_PAYMENT']);
            $rows[$m]     = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id, 'user_id' => $this->user->id, 'period_year' => 2026,
                'period_month' => $m, 'amount' => '1000.00', 'refrend_id' => $refrends[$m]->id,
                'status' => ScholarshipAdvancePaymentMonth::STATUS_PENDING,
            ]);
        }

        return ['origin' => $origin, 'header' => $header, 'refrends' => $refrends, 'months' => $rows];
    }

    private function pendingWithholding(int $month): ScholarshipWithholding
    {
        $origin = $this->refrend($month, ['status' => 'WITHHELD']);

        return ScholarshipWithholding::create([
            'user_id' => $this->user->id, 'origin_refrend_id' => $origin->id, 'period_year' => 2026,
            'period_month' => $month, 'withheld_amount' => '100.00', 'paid_amount' => '0.00', 'status' => 'PENDING',
        ]);
    }

    /** @test */
    public function an_egreso_cancels_withholdings_and_advances_with_a_null_actor_and_closes_no_refrend(): void
    {
        $f = $this->advance([4, 5]);
        $w = $this->pendingWithholding(2);

        $result = $this->canceller->cancel(CutAnchor::fromRefrend($f['refrends'][4]), WithholdingCancellationReason::EGRESO_RETICULA, null);

        $this->assertSame(1, $result->withholdings->count);
        $this->assertSame('2000.00', $result->advancePlan->cancelledAmount);
        $this->assertSame([], $result->closedRefrendIds);

        $this->assertSame('CANCELLED', $w->fresh()->status);
        $this->assertSame(WithholdingCancellationReason::EGRESO_RETICULA, $w->fresh()->cancellation_reason);
        $header = $f['header']->fresh();
        $this->assertSame('EGRESO_RETICULA', $header->cancel_reason);
        $this->assertNull($header->cancelled_by_id);
        $this->assertSame('DRAFT', $f['refrends'][5]->fresh()->workflow_status);
        $this->assertSame(1, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->count());
        $this->assertStringContainsString(
            'EGRESO POR RETÍCULA',
            ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->first()->notes
        );
    }

    /** @test */
    public function a_deactivation_cancels_everything_and_closes_the_refrends_of_cancelled_advance_months(): void
    {
        $f = $this->advance([4, 5, 6]);
        $w = $this->pendingWithholding(2);
        $anchor = CutAnchor::forDeactivation($this->user->id, Carbon::create(2026, 5, 10));

        $result = $this->canceller->cancel($anchor, WithholdingCancellationReason::USER_DEACTIVATED, $this->actor->id);

        $this->assertSame(WithholdingCancellationReason::USER_DEACTIVATED, $w->fresh()->cancellation_reason);
        $this->assertSame('USER_DEACTIVATED', $f['header']->fresh()->cancel_reason);
        $this->assertEqualsCanonicalizing([$f['refrends'][5]->id, $f['refrends'][6]->id], $result->closedRefrendIds);

        foreach ([5, 6] as $m) {
            $r = $f['refrends'][$m]->fresh();
            $this->assertSame('CANCELLED', $r->status->value);
            $this->assertSame('CLOSED', $r->workflow_status);
            $this->assertSame('0.00', $r->final_amount);
            $this->assertNotNull($r->locked_at);
            $this->assertSame($this->actor->id, (int) $r->locked_by_id);
            $this->assertSame('Cancelado por DESACTIVACIÓN DE USUARIO del 05/2026', $r->resolution_notes);
        }

        // The month before the anchor was consumed history and stays as it was.
        $this->assertSame('DRAFT', $f['refrends'][4]->fresh()->workflow_status);
        $this->assertSame('PENDING', $f['months'][4]->fresh()->status);
    }

    /** @test */
    public function a_locked_anchor_month_is_left_alone_and_not_closed_again(): void
    {
        $f = $this->advance([4, 5]);
        $f['refrends'][4]->update(['workflow_status' => 'CLOSED', 'locked_at' => now(), 'locked_by_id' => $this->actor->id]);
        $anchor = CutAnchor::forDeactivation($this->user->id, Carbon::create(2026, 4, 10));

        $result = $this->canceller->cancel($anchor, WithholdingCancellationReason::USER_DEACTIVATED, $this->actor->id);

        $this->assertSame([$f['refrends'][5]->id], $result->closedRefrendIds);
        $this->assertNull($f['refrends'][4]->fresh()->resolution_notes);
        $this->assertSame('PENDING', $f['months'][4]->fresh()->status);
    }

    /** @test */
    public function a_blocked_advance_rolls_back_the_withholding_cancellation(): void
    {
        $f = $this->advance([4, 5]);
        $f['refrends'][5]->update(['workflow_status' => 'CLOSED', 'locked_at' => now(), 'locked_by_id' => $this->actor->id]);
        $w = $this->pendingWithholding(2);
        $anchor = CutAnchor::forDeactivation($this->user->id, Carbon::create(2026, 4, 10));

        try {
            $this->canceller->cancel($anchor, WithholdingCancellationReason::USER_DEACTIVATED, $this->actor->id);
            $this->fail('Expected CycleCutBlockedException');
        } catch (CycleCutBlockedException) {
        }

        $this->assertSame('PENDING', $w->fresh()->status);
        $this->assertNull($w->fresh()->cancelled_at);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $f['header']->fresh()->status);
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'WITHHOLDING_CANCELLED')->count());
    }

    /** @test */
    public function running_it_twice_is_a_no_op_the_second_time(): void
    {
        $f = $this->advance([4, 5]);
        $this->pendingWithholding(2);
        $anchor = CutAnchor::fromRefrend($f['refrends'][4]);

        $this->canceller->cancel($anchor, WithholdingCancellationReason::EGRESO_RETICULA, null);
        $second = $this->canceller->cancel($anchor, WithholdingCancellationReason::EGRESO_RETICULA, null);

        $this->assertSame(0, $second->withholdings->count);
        $this->assertTrue($second->advancePlan->isEmpty());
        $this->assertSame(1, ScholarshipRefrendLog::where('action', 'WITHHOLDING_CANCELLED')->count());
        $this->assertSame(1, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->count());
    }

    /** @test */
    public function reasons_other_than_egreso_or_deactivation_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->canceller->cancel(
            CutAnchor::forDeactivation($this->user->id, Carbon::create(2026, 5, 1)),
            WithholdingCancellationReason::RECLASSIFIED,
            null
        );
    }
}
