<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendManualPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/bank-file-split-other-banks-cheques PR1 (R3): model, relation, factory.
 */
class ManualPaymentModelTest extends TestCase
{
    use RefreshDatabase;

    private function refrend(): ScholarshipRefrend
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        return ScholarshipRefrend::create([
            'user_id'         => $user->id,
            'period_year'     => 2026,
            'period_month'    => 5,
            'refrend_type'    => RefrendType::NORMAL->value,
            'status'          => RefrendStatus::DRAFT->value,
            'workflow_status' => 'CLOSED',
            'base_amount'     => 1000,
            'discount_amount' => 0,
            'final_amount'    => 1000,
            'snapshot_name'   => 'Becario ' . $user->id,
        ]);
    }

    public function test_fillable_lists_the_payment_fields(): void
    {
        $this->assertSame([
            'refrend_id', 'concept', 'method', 'reference', 'reference_normalized',
            'amount', 'paid_at', 'notes', 'created_by_id',
        ], (new ScholarshipRefrendManualPayment())->getFillable());
    }

    public function test_refrend_has_many_manual_payments(): void
    {
        $refrend = $this->refrend();
        ScholarshipRefrendManualPayment::factory()->create(['refrend_id' => $refrend->id, 'concept' => 'beca']);
        ScholarshipRefrendManualPayment::factory()->create(['refrend_id' => $refrend->id, 'concept' => 'apoyo']);

        $this->assertCount(2, $refrend->manualPayments);
        $this->assertTrue($refrend->manualPayments->first()->refrend->is($refrend));
    }

    public function test_factory_defaults_normalize_the_reference_and_keep_amount_two_decimals(): void
    {
        $payment = ScholarshipRefrendManualPayment::factory()->create([
            'refrend_id' => $this->refrend()->id,
            'reference'  => ' AB-1 ',
            'amount'     => 800,
        ])->fresh();

        $this->assertSame('ab-1', $payment->reference_normalized);
        $this->assertSame('800.00', $payment->amount);
        $this->assertSame('2026-05-20', $payment->paid_at->toDateString());
    }
}
