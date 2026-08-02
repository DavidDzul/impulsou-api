<?php

namespace Tests\Feature;

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

class ScholarshipWithholdingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
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

    /** @test */
    public function it_lists_only_pending_withholdings_with_balance_ordered_by_period(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $action = $this->app->make(RecordPaymentSituationAction::class);
        $this->actingAs($this->admin);

        $marRefrend = $this->makeRefrend($user->id, ['period_month' => 3]);
        $action->execute($marRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 150, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);

        $janRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $action->execute($janRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);

        $aprRefrend = $this->makeRefrend($user->id, ['period_month' => 4]);
        $action->execute($aprRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 200, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);

        // Fully settle january -> must NOT be listed.
        $janLedger = ScholarshipWithholding::where('origin_refrend_id', $janRefrend->id)->first();
        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 5]);
        $action->execute($payingRefrend, [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $janLedger->id, 'amount' => 300.00],
            ],
        ], $this->admin->id);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$user->id}/scholarship-withholdings?status=pending");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertSame(3, $data[0]['period_month']);
        $this->assertSame(4, $data[1]['period_month']);
    }

    /** @test */
    public function it_embeds_active_payments_and_excludes_voided_ones(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->actingAs($this->admin);

        $action = $this->app->make(RecordPaymentSituationAction::class);

        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $action->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $action->execute($payingRefrend, [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $ledger->id, 'amount' => 100.00],
            ],
        ], $this->admin->id);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$user->id}/scholarship-withholdings?status=pending");

        $response->assertStatus(200);
        $payments = $response->json('data.0.payments');
        $this->assertCount(1, $payments);
        $this->assertSame('100.00', $payments[0]['amount']);
    }

    /** @test */
    public function situation_endpoint_accepts_withholding_payments_and_persists_totals(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->actingAs($this->admin);

        $action = $this->app->make(RecordPaymentSituationAction::class);
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $action->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-refrends/{$payingRefrend->id}/situation", [
                'resolution_type'      => 'BECA_MES',
                'withholding_payments' => [
                    ['withholding_id' => $ledger->id, 'amount' => 150.00],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertSame('150.00', $response->json('data.amount_pending_from_previous'));
    }

    /** @test */
    public function situation_endpoint_rejects_duplicate_withholding_ids_in_payload(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->actingAs($this->admin);

        $action = $this->app->make(RecordPaymentSituationAction::class);
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $action->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $ledger = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-refrends/{$payingRefrend->id}/situation", [
                'resolution_type'      => 'BECA_MES',
                'withholding_payments' => [
                    ['withholding_id' => $ledger->id, 'amount' => 50.00],
                    ['withholding_id' => $ledger->id, 'amount' => 50.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_payments.0.withholding_id']);
    }

    // ── Void payment endpoint (PR4) ────────────────────────────────────────────

    /** @test */
    public function void_endpoint_rejects_missing_void_reason(): void
    {
        [$withholding, $payment] = $this->makeWithholdingWithOnePayment();

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/scholarship-withholdings/{$withholding->id}/payments/{$payment->id}/void", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['void_reason']);
    }

    /** @test */
    public function void_endpoint_rejects_void_reason_shorter_than_ten_chars(): void
    {
        [$withholding, $payment] = $this->makeWithholdingWithOnePayment();

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/scholarship-withholdings/{$withholding->id}/payments/{$payment->id}/void", [
                'void_reason' => 'corto',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['void_reason']);
    }

    /** @test */
    public function void_endpoint_reverts_the_payment_and_restores_the_balance(): void
    {
        [$withholding, $payment] = $this->makeWithholdingWithOnePayment();

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/scholarship-withholdings/{$withholding->id}/payments/{$payment->id}/void", [
                'void_reason' => 'Motivo suficientemente largo para pasar la validación.',
            ]);

        $response->assertStatus(200);
        $this->assertTrue((bool) $response->json('data.is_voided'));

        $withholding->refresh();
        $this->assertSame('0.00', $withholding->paid_amount);
        $this->assertSame('PENDING', $withholding->status);
    }

    /** @test */
    public function void_endpoint_returns_404_when_payment_does_not_belong_to_the_url_withholding(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->actingAs($this->admin);
        $action = $this->app->make(RecordPaymentSituationAction::class);

        [$withholdingA, $paymentA] = $this->makeWithholdingWithOnePayment();

        $originB = $this->makeRefrend($user->id, ['period_month' => 5]);
        $action->execute($originB, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 50, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $withholdingB = ScholarshipWithholding::where('origin_refrend_id', $originB->id)->first();

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/scholarship-withholdings/{$withholdingB->id}/payments/{$paymentA->id}/void", [
                'void_reason' => 'Motivo suficientemente largo para pasar la validación.',
            ]);

        $response->assertStatus(404);
    }

    /**
     * @return array{0: ScholarshipWithholding, 1: ScholarshipWithholdingPayment}
     */
    private function makeWithholdingWithOnePayment(): array
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->actingAs($this->admin);

        $action = $this->app->make(RecordPaymentSituationAction::class);
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $action->execute($originRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 150, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $withholding = ScholarshipWithholding::where('origin_refrend_id', $originRefrend->id)->first();

        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $action->execute($payingRefrend, [
            'resolution_type'      => 'BECA_MES',
            'withholding_payments' => [
                ['withholding_id' => $withholding->id, 'amount' => 150.00],
            ],
        ], $this->admin->id);

        $payment = ScholarshipWithholdingPayment::where('withholding_id', $withholding->id)->first();

        return [$withholding, $payment];
    }
}
