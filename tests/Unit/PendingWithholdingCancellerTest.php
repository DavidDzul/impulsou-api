<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Enums\WithholdingCancellationReason;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use App\Services\Scholarship\PendingWithholdingCanceller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/retenciones-pendientes, PR2 (R2, S13): cancels every PENDING withholding
 * of a becario, idempotently, leaving PAID / already CANCELLED rows untouched.
 */
class PendingWithholdingCancellerTest extends TestCase
{
    use RefreshDatabase;

    private PendingWithholdingCanceller $canceller;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canceller = $this->app->make(PendingWithholdingCanceller::class);
        $this->actor     = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function makeWithholding(User $user, int $month, string $withheld = '100.00', string $status = 'PENDING', string $paid = '0.00'): ScholarshipWithholding
    {
        $origin = ScholarshipRefrend::create([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => $month,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);

        return ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $origin->id,
            'period_year'       => 2026,
            'period_month'      => $month,
            'withheld_amount'   => $withheld,
            'paid_amount'       => $paid,
            'status'            => $status,
        ]);
    }

    private function becario(): User
    {
        return User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
    }

    /** @test */
    public function it_cancels_every_pending_withholding_with_reason_actor_and_timestamp(): void
    {
        $user = $this->becario();
        $a    = $this->makeWithholding($user, 3, '100.00');
        $b    = $this->makeWithholding($user, 4, '250.50');

        $result = $this->canceller->cancelAll($user->id, WithholdingCancellationReason::CYCLE_CUT, $this->actor->id);

        $this->assertSame(2, $result->count);
        $this->assertSame([$a->id, $b->id], $result->withholdingIds);
        $this->assertSame('350.50', $result->totalRemaining);

        foreach ([$a, $b] as $w) {
            $fresh = $w->fresh();
            $this->assertSame('CANCELLED', $fresh->status);
            $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $fresh->cancellation_reason);
            $this->assertNotNull($fresh->cancelled_at);
            $this->assertSame($this->actor->id, (int) $fresh->cancelled_by_id);
        }
    }

    /** @test */
    public function it_accepts_a_null_actor_for_automatic_paths(): void
    {
        $user = $this->becario();
        $w    = $this->makeWithholding($user, 3);

        $this->canceller->cancelAll($user->id, WithholdingCancellationReason::EGRESO_RETICULA, null);

        $fresh = $w->fresh();
        $this->assertSame('CANCELLED', $fresh->status);
        $this->assertSame(WithholdingCancellationReason::EGRESO_RETICULA, $fresh->cancellation_reason);
        $this->assertNull($fresh->cancelled_by_id);
    }

    /** @test */
    public function it_logs_withholding_cancelled_on_the_origin_refrend(): void
    {
        $user = $this->becario();
        $w    = $this->makeWithholding($user, 3, '100.00');

        $this->canceller->cancelAll($user->id, WithholdingCancellationReason::USER_DEACTIVATED, $this->actor->id);

        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $w->origin_refrend_id)
            ->where('action', 'WITHHOLDING_CANCELLED')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->actor->id, (int) $log->performed_by_id);
        $this->assertSame('PENDING', $log->old_values['status']);
        $this->assertSame('CANCELLED', $log->new_values['status']);
        $this->assertSame('USER_DEACTIVATED', $log->new_values['cancellation_reason']);
        $this->assertSame($w->id, $log->new_values['withholding_id']);
    }

    /** @test */
    public function it_leaves_paid_and_already_cancelled_withholdings_untouched(): void
    {
        $user      = $this->becario();
        $paid      = $this->makeWithholding($user, 2, '100.00', 'PAID', '100.00');
        $cancelled = $this->makeWithholding($user, 3, '100.00', 'CANCELLED');
        $pending   = $this->makeWithholding($user, 4);

        $result = $this->canceller->cancelAll($user->id, WithholdingCancellationReason::CYCLE_CUT, $this->actor->id);

        $this->assertSame(1, $result->count);
        $this->assertSame('PAID', $paid->fresh()->status);
        $this->assertNull($paid->fresh()->cancelled_at);
        $this->assertSame('CANCELLED', $cancelled->fresh()->status);
        $this->assertNull($cancelled->fresh()->cancellation_reason);
        $this->assertSame('CANCELLED', $pending->fresh()->status);
    }

    /** @test */
    public function it_does_not_touch_other_users_withholdings(): void
    {
        $user  = $this->becario();
        $other = $this->becario();
        $this->makeWithholding($user, 3);
        $theirs = $this->makeWithholding($other, 3);

        $this->canceller->cancelAll($user->id, WithholdingCancellationReason::CYCLE_CUT, $this->actor->id);

        $this->assertSame('PENDING', $theirs->fresh()->status);
    }

    /** @test */
    public function a_second_call_cancels_nothing_and_writes_no_duplicate_log(): void
    {
        $user = $this->becario();
        $this->makeWithholding($user, 3);

        $this->canceller->cancelAll($user->id, WithholdingCancellationReason::CYCLE_CUT, $this->actor->id);
        $second = $this->canceller->cancelAll($user->id, WithholdingCancellationReason::USER_DEACTIVATED, $this->actor->id);

        $this->assertSame(0, $second->count);
        $this->assertSame([], $second->withholdingIds);
        $this->assertSame('0.00', $second->totalRemaining);
        $this->assertSame(1, ScholarshipRefrendLog::where('action', 'WITHHOLDING_CANCELLED')->count());
        $this->assertSame(
            WithholdingCancellationReason::CYCLE_CUT,
            ScholarshipWithholding::where('user_id', $user->id)->first()->cancellation_reason
        );
    }

    /** @test */
    public function a_partially_paid_pending_row_is_cancelled_with_its_paid_amount_frozen_and_abonos_kept(): void
    {
        $user     = $this->becario();
        $w        = $this->makeWithholding($user, 3, '100.00', 'PENDING', '40.00');
        $applied  = ScholarshipRefrend::create([
            'user_id' => $user->id, 'period_year' => 2026, 'period_month' => 6, 'status' => 'DRAFT',
            'workflow_status' => 'DRAFT', 'base_amount' => 1000, 'discount_percentage' => 0,
            'discount_amount' => 0, 'final_amount' => 1000, 'amount_pending_from_previous' => 0,
            'snapshot_name' => 'T', 'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ]);
        $abono = ScholarshipWithholdingPayment::create([
            'withholding_id' => $w->id, 'applied_refrend_id' => $applied->id,
            'amount' => '40.00', 'created_by_id' => $this->actor->id,
        ]);

        $result = $this->canceller->cancelAll($user->id, WithholdingCancellationReason::CYCLE_CUT, $this->actor->id);

        $fresh = $w->fresh();
        $this->assertSame(1, $result->count);
        $this->assertSame('60.00', $result->totalRemaining);
        $this->assertSame('CANCELLED', $fresh->status);
        $this->assertSame('40.00', $fresh->paid_amount);

        $fresh->recomputePaidAmount();
        $this->assertSame('40.00', $fresh->fresh()->paid_amount);
        $this->assertFalse((bool) $abono->fresh()->is_voided);
    }
}
