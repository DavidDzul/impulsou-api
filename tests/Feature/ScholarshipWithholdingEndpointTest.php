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

        // Fully settle january -> must NOT be listed. Paying refrend's period must
        // stay within the 3-month payable window of january (retencion-limite-3-meses
        // B4 enforces this at the Action layer as defense-in-depth) — month 2 is
        // offset 1 from january, distinct from the other periods used above (1/3/4).
        $janLedger = ScholarshipWithholding::where('origin_refrend_id', $janRefrend->id)->first();
        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
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

        // Seeded directly (legacy-style partial child rows, bypassing the
        // now-stricter RecordPaymentSituationAction) so the ledger stays
        // PENDING with one active and one voided payment to embed.
        $payingRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $ledger->id,
            'applied_refrend_id' => $payingRefrend->id,
            'amount'             => '100.00',
            'created_by_id'      => $this->admin->id,
        ]);
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $ledger->id,
            'applied_refrend_id' => $payingRefrend->id,
            'amount'             => '50.00',
            'created_by_id'      => $this->admin->id,
            'is_voided'          => true,
        ]);
        $ledger->recomputePaidAmount();

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
                    ['withholding_id' => $ledger->id, 'amount' => 300.00],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertSame('300.00', $response->json('data.amount_pending_from_previous'));
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
                    // Exact-match amounts so this 422 is attributable ONLY to
                    // the duplicate-id rule.
                    ['withholding_id' => $ledger->id, 'amount' => 300.00],
                    ['withholding_id' => $ledger->id, 'amount' => 300.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_payments.0.withholding_id']);
    }

    // ── relative_year/relative_month params + meta block ──────────────────────

    /** @test */
    public function it_returns_legacy_response_without_meta_when_relative_params_are_absent(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->actingAs($this->admin);
        $action = $this->app->make(RecordPaymentSituationAction::class);

        $refrend = $this->makeRefrend($user->id, ['period_month' => 7]);
        $action->execute($refrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 100, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$user->id}/scholarship-withholdings");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertArrayNotHasKey('meta', $response->json());
    }

    /** @test */
    public function it_returns_payable_subset_and_meta_when_relative_params_are_both_present(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->actingAs($this->admin);
        $action = $this->app->make(RecordPaymentSituationAction::class);

        $recentRefrend = $this->makeRefrend($user->id, ['period_month' => 7]); // offset 1
        $action->execute($recentRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 500, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $recentLedger = ScholarshipWithholding::where('origin_refrend_id', $recentRefrend->id)->first();

        $olderRefrend = $this->makeRefrend($user->id, ['period_month' => 6]); // offset 2
        $action->execute($olderRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 300, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);
        $olderLedger = ScholarshipWithholding::where('origin_refrend_id', $olderRefrend->id)->first();

        // 3rd in-window entry -> excluded by top-2 rank, still counted in total_pending.
        $oldestRefrend = $this->makeRefrend($user->id, ['period_month' => 5]); // offset 3
        $action->execute($oldestRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 200, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);

        // Out of window entirely -> excluded, still counted in total_pending.
        $outOfWindowRefrend = $this->makeRefrend($user->id, ['period_month' => 3]); // offset 5
        $action->execute($outOfWindowRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 100, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$user->id}/scholarship-withholdings?relative_year=2026&relative_month=8");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame([$recentLedger->id, $olderLedger->id], array_column($data, 'id'));

        $meta = $response->json('meta');
        $this->assertSame(2026, $meta['relative_year']);
        $this->assertSame(8, $meta['relative_month']);
        $this->assertSame(2, $meta['eligible_count']);
        $this->assertSame(4, $meta['total_pending_count']);
        $this->assertSame('1100.00', $meta['total_pending_amount']);
    }

    /** @test */
    public function it_returns_zero_eligible_count_with_nonzero_total_pending_when_only_stale_debt_exists(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->actingAs($this->admin);
        $action = $this->app->make(RecordPaymentSituationAction::class);

        $staleRefrend = $this->makeRefrend($user->id, ['period_month' => 3]); // offset 5
        $action->execute($staleRefrend, [
            'resolution_type' => 'RETENIDA', 'withholding_mode' => 'fixed',
            'withholding_value' => 400, 'resolution_cause' => 'OTRO',
        ], $this->admin->id);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$user->id}/scholarship-withholdings?relative_year=2026&relative_month=8");

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));

        $meta = $response->json('meta');
        $this->assertSame(0, $meta['eligible_count']);
        $this->assertSame(1, $meta['total_pending_count']);
        $this->assertSame('400.00', $meta['total_pending_amount']);
    }

    /** @test */
    public function it_returns_422_when_only_relative_year_is_sent(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$user->id}/scholarship-withholdings?relative_year=2026");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['relative_month']);
    }

    /** @test */
    public function it_returns_422_when_only_relative_month_is_sent(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/users/{$user->id}/scholarship-withholdings?relative_month=8");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['relative_year']);
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
