<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithholdingFullSettlementAmountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);
    }

    private function makeRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'active'    => true,
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
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

    private function situationUrl(int $id): string
    {
        return "/api/admin/scholarship-refrends/{$id}/situation";
    }

    private function makeWithholdingForUser(int $userId, int $periodYear, int $periodMonth, float $withheld = 500.00): ScholarshipWithholding
    {
        $originRefrend = $this->makeRefrend([
            'user_id'      => $userId,
            'period_year'  => $periodYear,
            'period_month' => $periodMonth,
            'status'       => 'WITHHELD',
        ]);

        return ScholarshipWithholding::create([
            'user_id'           => $userId,
            'origin_refrend_id' => $originRefrend->id,
            'period_year'       => $periodYear,
            'period_month'      => $periodMonth,
            'withheld_amount'   => number_format($withheld, 2, '.', ''),
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);
    }

    // ── recordSituation validation ──────────────────────────────────────────

    /** @test */
    public function it_returns_422_when_withholding_value_exceeds_100_in_percentage_mode(): void
    {
        $refrend = $this->makeRefrend();

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'RETENIDA',
                'withholding_mode'   => 'percentage',
                'withholding_value'  => 150,
                'resolution_cause'   => 'BAJO_PROMEDIO',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_value']);
    }

    /** @test */
    public function it_returns_422_when_withholding_value_exceeds_base_amount_in_fixed_mode(): void
    {
        $refrend = $this->makeRefrend(['base_amount' => 1000.00]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'RETENIDA',
                'withholding_mode'   => 'fixed',
                'withholding_value'  => 1500,
                'resolution_cause'   => 'BAJO_PROMEDIO',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_value']);
    }

    /** @test */
    public function it_accepts_valid_partial_withholding_and_persists_amounts(): void
    {
        $refrend = $this->makeRefrend(['base_amount' => 1000.00]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'RETENIDA',
                'withholding_mode'   => 'percentage',
                'withholding_value'  => 30,
                'resolution_cause'   => 'BAJO_PROMEDIO',
            ]);

        $response->assertStatus(200);
        $this->assertSame('300.00', $response->json('data.discount_amount'));
        $this->assertSame('700.00', $response->json('data.final_amount'));
    }

    // ── recordSituation withholding_payments window validation ────────────────

    /** @test */
    public function it_returns_422_when_withholding_payment_references_an_out_of_window_id(): void
    {
        $refrend = $this->makeRefrend(['period_year' => 2026, 'period_month' => 8]);
        $stale   = $this->makeWithholdingForUser($refrend->user_id, 2026, 3, 400.00); // offset 5, out of window

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'      => 'SIN_PAGO',
                'withholding_payments' => [
                    // Exact-match amount so this 422 is attributable ONLY to the
                    // window rule, not the amount rule.
                    ['withholding_id' => $stale->id, 'amount' => 400.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_payments.0.withholding_id']);
        $this->assertDatabaseCount('scholarship_withholding_payments', 0);
    }

    /** @test */
    public function it_returns_422_when_three_withholding_payments_are_submitted(): void
    {
        $refrend = $this->makeRefrend(['period_year' => 2026, 'period_month' => 8]);
        $first   = $this->makeWithholdingForUser($refrend->user_id, 2026, 7, 100.00); // offset 1
        $second  = $this->makeWithholdingForUser($refrend->user_id, 2026, 6, 100.00); // offset 2
        $third   = $this->makeWithholdingForUser($refrend->user_id, 2026, 5, 100.00); // offset 3, in-window but rank 3rd

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'      => 'SIN_PAGO',
                'withholding_payments' => [
                    // Exact-match amounts (100.00 == withheld) so this 422 is
                    // attributable ONLY to the "no more than 2" rule.
                    ['withholding_id' => $first->id, 'amount' => 100.00],
                    ['withholding_id' => $second->id, 'amount' => 100.00],
                    ['withholding_id' => $third->id, 'amount' => 100.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_payments']);
        $this->assertDatabaseCount('scholarship_withholding_payments', 0);
    }

    /** @test */
    public function it_persists_payments_for_two_eligible_withholding_ids(): void
    {
        $refrend = $this->makeRefrend(['period_year' => 2026, 'period_month' => 8]);
        $recent  = $this->makeWithholdingForUser($refrend->user_id, 2026, 7, 100.00); // offset 1
        $older   = $this->makeWithholdingForUser($refrend->user_id, 2026, 6, 100.00); // offset 2

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'      => 'SIN_PAGO',
                'withholding_payments' => [
                    ['withholding_id' => $recent->id, 'amount' => 100.00],
                    ['withholding_id' => $older->id, 'amount' => 100.00],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('scholarship_withholding_payments', 2);
    }

    /** @test */
    public function it_rejects_under_submission_with_422_and_creates_no_payment_rows(): void
    {
        $refrend = $this->makeRefrend(['period_year' => 2026, 'period_month' => 8]);
        $target  = $this->makeWithholdingForUser($refrend->user_id, 2026, 7, 500.00); // offset 1

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'      => 'SIN_PAGO',
                'withholding_payments' => [
                    ['withholding_id' => $target->id, 'amount' => 300.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_payments.0.amount']);
        $this->assertDatabaseCount('scholarship_withholding_payments', 0);
    }

    /** @test */
    public function it_rejects_over_submission_with_422_instead_of_clamping(): void
    {
        $refrend = $this->makeRefrend(['period_year' => 2026, 'period_month' => 8]);
        $target  = $this->makeWithholdingForUser($refrend->user_id, 2026, 7, 500.00); // offset 1

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'      => 'SIN_PAGO',
                'withholding_payments' => [
                    ['withholding_id' => $target->id, 'amount' => 650.00],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_payments.0.amount']);
        $this->assertDatabaseCount('scholarship_withholding_payments', 0);
    }

    /** @test */
    public function it_settles_a_legacy_grandfathered_entry_for_its_current_remaining_balance(): void
    {
        $refrend = $this->makeRefrend(['period_year' => 2026, 'period_month' => 8]);
        $target  = $this->makeWithholdingForUser($refrend->user_id, 2026, 7, 500.00); // offset 1

        // Real child payment row seeded directly, simulating a pre-existing
        // partial payment applied before this rule existed — not a migration,
        // just how legacy data looks. The row stays payable for its CURRENT
        // remaining_amount (300.00), no backfill required.
        $earlierRefrend = $this->makeRefrend(['period_year' => 2026, 'period_month' => 6]);
        ScholarshipWithholdingPayment::create([
            'withholding_id'     => $target->id,
            'applied_refrend_id' => $earlierRefrend->id,
            'amount'             => '200.00',
            'created_by_id'      => $this->admin->id,
        ]);
        $target->recomputePaidAmount();
        $target->refresh();
        $this->assertSame('300.00', $target->remaining_amount);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'      => 'SIN_PAGO',
                'withholding_payments' => [
                    ['withholding_id' => $target->id, 'amount' => 300.00],
                ],
            ]);

        $response->assertStatus(200);
        $target->refresh();
        $this->assertSame('PAID', $target->status);
        $this->assertSame('0.00', $target->remaining_amount);
    }

    /** @test */
    public function descuento_definitivo_rows_are_unaffected_by_the_window_rule(): void
    {
        $refrend = $this->makeRefrend(['period_year' => 2026, 'period_month' => 8, 'base_amount' => 1000.00]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'DESCUENTO_DEFINITIVO',
                'withholding_mode'   => 'fixed',
                'withholding_value'  => 200,
            ]);

        $response->assertStatus(200);
    }

    // ── bulkPay ──────────────────────────────────────────────────────────────

    /** @test */
    public function bulk_pay_pays_withheld_refrend_when_final_amount_is_positive(): void
    {
        $refrend = $this->makeRefrend([
            'workflow_status' => 'LISTO_PARA_PAGO',
            'status'          => RefrendStatus::WITHHELD->value,
            'discount_amount' => 300.00,
            'final_amount'    => 700.00,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-refrends/bulk/pay', ['ids' => [$refrend->id]]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.paid'));

        $refrend->refresh();
        $this->assertSame(RefrendStatus::PAID, $refrend->status);
        $this->assertSame('CLOSED', $refrend->workflow_status);
    }

    /** @test */
    public function bulk_pay_skips_withheld_refrend_when_final_amount_is_zero(): void
    {
        $refrend = $this->makeRefrend([
            'workflow_status' => 'LISTO_PARA_PAGO',
            'status'          => RefrendStatus::WITHHELD->value,
            'discount_amount' => 1000.00,
            'final_amount'    => 0.00,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-refrends/bulk/pay', ['ids' => [$refrend->id]]);

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.paid'));

        $refrend->refresh();
        $this->assertSame(RefrendStatus::WITHHELD, $refrend->status);
        $this->assertSame('LISTO_PARA_PAGO', $refrend->workflow_status);
    }
}
