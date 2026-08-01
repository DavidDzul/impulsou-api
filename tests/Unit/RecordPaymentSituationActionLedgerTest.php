<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordPaymentSituationActionLedgerTest extends TestCase
{
    use RefreshDatabase;

    private RecordPaymentSituationAction $action;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(RecordPaymentSituationAction::class);
        $this->admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
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

    // ── Ledger creation ──────────────────────────────────────────────────────

    /** @test */
    public function retenida_creates_one_ledger_row_with_withheld_amount_equal_to_discount_amount(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);

        $result = $this->action->execute($refrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $ledger = ScholarshipWithholding::where('origin_refrend_id', $result->id)->first();
        $this->assertNotNull($ledger);
        $this->assertSame($result->discount_amount, $ledger->withheld_amount);
        $this->assertSame('PENDING', $ledger->status);
        $this->assertSame('0.00', $ledger->paid_amount);
        $this->assertSame($user->id, $ledger->user_id);
    }

    /** @test */
    public function re_saving_retenida_updates_the_same_ledger_row_instead_of_duplicating(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);

        $this->action->execute($refrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $this->action->execute($refrend->fresh(), [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 50,
            'resolution_cause'  => 'OTRO',
        ], $this->admin->id);

        $this->assertSame(1, ScholarshipWithholding::where('origin_refrend_id', $refrend->id)->count());
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $refrend->id)->first();
        $this->assertSame('500.00', $ledger->withheld_amount);
    }

    /** @test */
    public function reclassifying_away_from_retenida_without_payments_cancels_the_ledger_row(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);

        $this->action->execute($refrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $this->action->execute($refrend->fresh(), [
            'resolution_type' => 'BECA_MES',
        ], $this->admin->id);

        $ledger = ScholarshipWithholding::where('origin_refrend_id', $refrend->id)->first();
        $this->assertSame('CANCELLED', $ledger->status);
    }

    /** @test */
    public function reclassifying_a_retention_with_live_payments_throws_domain_exception(): void
    {
        $user           = $this->makeBecario();
        $originRefrend  = $this->makeRefrend($user->id, ['period_month' => 1]);

        $this->action->execute($originRefrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->action->execute($payingRefrend, [
            'resolution_type'       => 'BECA_MES',
            'withholding_payments'  => [
                ['withholding_id' => $ledger->id, 'amount' => 100.00],
            ],
        ], $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->action->execute($originRefrend->fresh(), [
            'resolution_type' => 'BECA_MES',
        ], $this->admin->id);
    }

    // ── Withholding payments application ─────────────────────────────────────

    /** @test */
    public function withholding_payments_create_child_rows_and_recompute_paid_amount(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);

        $this->action->execute($originRefrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'percentage',
            'withholding_value' => 30,
            'resolution_cause'  => 'BAJO_PROMEDIO',
        ], $this->admin->id);

        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();
        $this->assertSame('300.00', $ledger->withheld_amount);

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $result = $this->action->execute($payingRefrend, [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $ledger->id, 'amount' => 150.00],
            ],
        ], $this->admin->id);

        $child = ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)->first();
        $this->assertNotNull($child);
        $this->assertSame('150.00', $child->amount);
        $this->assertSame($payingRefrend->id, $child->applied_refrend_id);
        $this->assertSame($this->admin->id, $child->created_by_id);

        $ledger->refresh();
        $this->assertSame('150.00', $ledger->paid_amount);
        $this->assertSame('PENDING', $ledger->status);
        $this->assertSame('150.00', $ledger->remaining_amount);

        $this->assertSame('150.00', $result->amount_pending_from_previous);
        $this->assertSame(1, $result->carryover_months_count);
    }

    /** @test */
    public function full_payment_settles_the_ledger_row_as_paid(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);

        $this->action->execute($originRefrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'fixed',
            'withholding_value' => 300,
            'resolution_cause'  => 'OTRO',
        ], $this->admin->id);

        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->action->execute($payingRefrend, [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $ledger->id, 'amount' => 300.00],
            ],
        ], $this->admin->id);

        $ledger->refresh();
        $this->assertSame('PAID', $ledger->status);
        $this->assertNotNull($ledger->settled_at);
        $this->assertSame('0.00', $ledger->remaining_amount);
    }

    /** @test */
    public function amount_greater_than_remaining_is_clamped_to_remaining(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);

        $this->action->execute($originRefrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'fixed',
            'withholding_value' => 100,
            'resolution_cause'  => 'OTRO',
        ], $this->admin->id);

        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->action->execute($payingRefrend, [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $ledger->id, 'amount' => 9999.00],
            ],
        ], $this->admin->id);

        $child = ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)->first();
        $this->assertSame('100.00', $child->amount);
    }

    /** @test */
    public function paying_a_withholding_of_another_becario_throws_domain_exception(): void
    {
        $userA = $this->makeBecario();
        $userB = $this->makeBecario();

        $refrendA = $this->makeRefrend($userA->id, ['period_month' => 1]);
        $this->action->execute($refrendA, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'fixed',
            'withholding_value' => 100,
            'resolution_cause'  => 'OTRO',
        ], $this->admin->id);
        $ledgerA = ScholarshipWithholding::where('origin_refrend_id', $refrendA->id)->first();

        $refrendB = $this->makeRefrend($userB->id, ['period_month' => 1]);

        $this->expectException(\DomainException::class);
        $this->action->execute($refrendB, [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $ledgerA->id, 'amount' => 50.00],
            ],
        ], $this->admin->id);
    }

    /** @test */
    public function a_refrend_cannot_liquidate_its_own_retention(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);

        $this->action->execute($refrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'fixed',
            'withholding_value' => 100,
            'resolution_cause'  => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $refrend->id)->first();

        $this->expectException(\DomainException::class);
        $this->action->execute($refrend->fresh(), [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $ledger->id, 'amount' => 50.00],
            ],
        ], $this->admin->id);
    }

    /** @test */
    public function re_registering_situation_with_live_payments_already_applied_throws(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $this->action->execute($originRefrend, [
            'resolution_type'   => 'RETENIDA',
            'withholding_mode'  => 'fixed',
            'withholding_value' => 300,
            'resolution_cause'  => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->action->execute($payingRefrend, [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $ledger->id, 'amount' => 100.00],
            ],
        ], $this->admin->id);

        $this->expectException(\DomainException::class);
        $this->action->execute($payingRefrend->fresh(), [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $ledger->id, 'amount' => 50.00],
            ],
        ], $this->admin->id);
    }
}
