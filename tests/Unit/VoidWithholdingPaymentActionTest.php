<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Actions\Scholarship\VoidWithholdingPaymentAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoidWithholdingPaymentActionTest extends TestCase
{
    use RefreshDatabase;

    private RecordPaymentSituationAction $recordAction;
    private VoidWithholdingPaymentAction $voidAction;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordAction = $this->app->make(RecordPaymentSituationAction::class);
        $this->voidAction   = $this->app->make(VoidWithholdingPaymentAction::class);
        $this->admin        = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);
    }

    private function makeRefrend(int $userId, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => 1,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
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

    private function makeBecario(): User
    {
        return User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);
    }

    /** @test */
    public function reverting_one_of_three_payments_leaves_the_others_intact_and_restores_that_amount_to_the_balance(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $this->recordAction->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        // Three separate refrends each pay off a slice of the same retention.
        $refrend1 = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->recordAction->execute($refrend1, [
            'resolution_type' => 'BECA_MES',
            'withholding_payments' => [['withholding_id' => $ledger->id, 'amount' => 100.00]],
        ], $this->admin->id);

        $refrend2 = $this->makeRefrend($user->id, ['period_month' => 3]);
        $this->recordAction->execute($refrend2, [
            'resolution_type' => 'BECA_MES',
            'withholding_payments' => [['withholding_id' => $ledger->id, 'amount' => 100.00]],
        ], $this->admin->id);

        $refrend3 = $this->makeRefrend($user->id, ['period_month' => 4]);
        $this->recordAction->execute($refrend3, [
            'resolution_type' => 'BECA_MES',
            'withholding_payments' => [['withholding_id' => $ledger->id, 'amount' => 100.00]],
        ], $this->admin->id);

        $ledger->refresh();
        $this->assertSame('300.00', $ledger->paid_amount);
        $this->assertSame('PAID', $ledger->status);

        $paymentToVoid = ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)
            ->where('applied_refrend_id', $refrend2->id)
            ->first();

        $this->voidAction->execute($paymentToVoid, 'Monto aplicado por error de captura.', $this->admin->id);

        $others = ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)
            ->where('id', '!=', $paymentToVoid->id)
            ->get();
        $this->assertCount(2, $others);
        $this->assertTrue($others->every(fn ($p) => !$p->is_voided));

        $ledger->refresh();
        $this->assertSame('200.00', $ledger->paid_amount);
        $this->assertSame('100.00', $ledger->remaining_amount);
    }

    /** @test */
    public function voiding_the_only_settling_payment_reopens_a_paid_retention_to_pending_with_null_settled_at(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $this->recordAction->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->recordAction->execute($payingRefrend, [
            'resolution_type' => 'BECA_MES',
            'withholding_payments' => [['withholding_id' => $ledger->id, 'amount' => 300.00]],
        ], $this->admin->id);

        $ledger->refresh();
        $this->assertSame('PAID', $ledger->status);
        $this->assertNotNull($ledger->settled_at);

        $payment = ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)->first();
        $this->voidAction->execute($payment, 'Reversión de prueba con motivo suficiente.', $this->admin->id);

        $ledger->refresh();
        $this->assertSame('PENDING', $ledger->status);
        $this->assertNull($ledger->settled_at);
        $this->assertSame('300.00', $ledger->remaining_amount);
    }

    /** @test */
    public function voiding_an_already_voided_payment_throws_domain_exception(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $this->recordAction->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->recordAction->execute($payingRefrend, [
            'resolution_type' => 'BECA_MES',
            'withholding_payments' => [['withholding_id' => $ledger->id, 'amount' => 150.00]],
        ], $this->admin->id);

        $payment = ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)->first();
        $this->voidAction->execute($payment, 'Motivo original de reversión.', $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->voidAction->execute($payment->fresh(), 'Segundo intento de reversión.', $this->admin->id);
    }

    /** @test */
    public function voiding_a_payment_whose_applied_refrend_is_locked_throws_domain_exception(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $this->recordAction->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->recordAction->execute($payingRefrend, [
            'resolution_type' => 'BECA_MES',
            'withholding_payments' => [['withholding_id' => $ledger->id, 'amount' => 150.00]],
        ], $this->admin->id);

        // Close the refrend where this payment was applied.
        $payingRefrend->fresh()->update(['workflow_status' => 'CLOSED']);

        $payment = ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)->first();

        $this->expectException(\DomainException::class);
        $this->voidAction->execute($payment, 'Motivo de reversión sobre refrendo cerrado.', $this->admin->id);
    }

    /** @test */
    public function voiding_a_payment_on_a_cancelled_withholding_throws_domain_exception(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $this->recordAction->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->recordAction->execute($payingRefrend, [
            'resolution_type' => 'BECA_MES',
            'withholding_payments' => [['withholding_id' => $ledger->id, 'amount' => 150.00]],
        ], $this->admin->id);

        $ledger->update(['status' => 'CANCELLED']);
        $payment = ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)->first();

        $this->expectException(\DomainException::class);
        $this->voidAction->execute($payment, 'Motivo de reversión sobre retención cancelada.', $this->admin->id);
    }

    /** @test */
    public function voiding_regenerates_the_applied_refrends_payment_totals_without_the_voided_amount(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $this->recordAction->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $janLedger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $marchOrigin = $this->makeRefrend($user->id, ['period_month' => 3]);
        $this->recordAction->execute($marchOrigin, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 150, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $marLedger = ScholarshipWithholding::where('origin_refrend_id', $marchOrigin->id)->first();

        $aprilRefrend = $this->makeRefrend($user->id, ['period_month' => 4]);
        $result = $this->recordAction->execute($aprilRefrend, [
            'resolution_type' => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $janLedger->id, 'amount' => 300.00],
                ['withholding_id' => $marLedger->id, 'amount' => 75.00],
            ],
        ], $this->admin->id);

        $this->assertSame('375.00', $result->amount_pending_from_previous);
        $this->assertSame(2, $result->carryover_months_count);

        $marPayment = ScholarshipWithholdingPayment::where('withholding_id', $marLedger->id)->first();
        $this->voidAction->execute($marPayment, 'Se anula el abono de marzo por error.', $this->admin->id);

        $aprilRefrend->refresh();
        $this->assertSame('300.00', $aprilRefrend->amount_pending_from_previous);
        $this->assertSame(1, $aprilRefrend->carryover_months_count);
        $this->assertStringNotContainsString('03/2026', $aprilRefrend->carryover_months_detail);

        $marLedger->refresh();
        $this->assertSame('150.00', $marLedger->remaining_amount);
    }
}
