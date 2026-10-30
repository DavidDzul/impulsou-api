<?php

namespace Tests\Feature;

use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Enums\WithholdingCancellationReason;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use App\Services\Scholarship\PendingWithholdingCanceller;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/retenciones-pendientes, PR2 (R3, S10): payment vs cancellation of the
 * same withholding are serialized by the row lock. Both orders are exercised
 * deterministically: the lock makes the second transaction re-read the status
 * the first one committed.
 */
class WithholdingCancellationPaymentRaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
        $this->actingAs($this->admin);
    }

    private function makeRefrend(int $userId, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => $month,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function pendingWithholding(User $user, int $month, float $amount = 100.00): ScholarshipWithholding
    {
        $origin = $this->makeRefrend($user->id, $month, ['status' => 'WITHHELD']);

        return ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $origin->id,
            'period_year'       => 2026,
            'period_month'      => $month,
            'withheld_amount'   => number_format($amount, 2, '.', ''),
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);
    }

    private function becario(): User
    {
        return User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
    }

    /** @test */
    public function the_action_rejects_a_payment_against_a_cancelled_withholding_and_creates_no_abono(): void
    {
        $user    = $this->becario();
        $payer   = $this->makeRefrend($user->id, 8);
        $w       = $this->pendingWithholding($user, 7);

        app(PendingWithholdingCanceller::class)->cancelAll($user->id, WithholdingCancellationReason::USER_DEACTIVATED, $this->admin->id);

        $this->expectException(\DomainException::class);

        try {
            app(RecordPaymentSituationAction::class)->execute($payer, [
                'resolution_type'      => 'SIN_PAGO',
                'withholding_payments' => [['withholding_id' => $w->id, 'amount' => 100.00]],
            ], $this->admin->id);
        } finally {
            $this->assertDatabaseCount('scholarship_withholding_payments', 0);
            $this->assertSame('CANCELLED', $w->fresh()->status);
            $this->assertSame('0.00', $w->fresh()->paid_amount);
        }
    }

    /** @test */
    public function the_endpoint_returns_422_for_a_payment_against_a_cancelled_withholding(): void
    {
        $user  = $this->becario();
        $payer = $this->makeRefrend($user->id, 8);
        $w     = $this->pendingWithholding($user, 7);

        app(PendingWithholdingCanceller::class)->cancelAll($user->id, WithholdingCancellationReason::CYCLE_CUT, $this->admin->id);

        $response = $this->postJson("/api/admin/scholarship-refrends/{$payer->id}/situation", [
            'resolution_type'      => 'SIN_PAGO',
            'withholding_payments' => [['withholding_id' => $w->id, 'amount' => 100.00]],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('scholarship_withholding_payments', 0);
    }

    /** @test */
    public function cancelling_after_a_payment_leaves_the_paid_withholding_untouched(): void
    {
        $user  = $this->becario();
        $payer = $this->makeRefrend($user->id, 8);
        $w     = $this->pendingWithholding($user, 7);

        app(RecordPaymentSituationAction::class)->execute($payer, [
            'resolution_type'      => 'SIN_PAGO',
            'withholding_payments' => [['withholding_id' => $w->id, 'amount' => 100.00]],
        ], $this->admin->id);

        $result = app(PendingWithholdingCanceller::class)->cancelAll($user->id, WithholdingCancellationReason::CYCLE_CUT, $this->admin->id);

        $fresh = $w->fresh();
        $this->assertSame(0, $result->count);
        $this->assertSame('PAID', $fresh->status);
        $this->assertSame('100.00', $fresh->paid_amount);
        $this->assertNull($fresh->cancelled_at);
        $this->assertNull($fresh->cancellation_reason);
    }
}
