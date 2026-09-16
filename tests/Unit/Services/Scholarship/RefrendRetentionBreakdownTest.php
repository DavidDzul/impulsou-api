<?php

namespace Tests\Unit\Services\Scholarship;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use App\Services\Scholarship\RefrendPaymentTotalsSyncer;
use App\Services\Scholarship\RefrendRetentionBreakdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for RefrendRetentionBreakdown — the READ-side twin of
 * RefrendPaymentTotalsSyncer (sdd/withholding-detail-display, design D1/D2).
 * Central correctness gate: the CANCELLED-vs-voided split (D2) and the
 * ledger_applied_total <-> amount_pending_from_previous reconciliation
 * invariant.
 */
class RefrendRetentionBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private RefrendRetentionBreakdown $breakdown;
    private RefrendPaymentTotalsSyncer $totalsSyncer;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->breakdown    = new RefrendRetentionBreakdown();
        $this->totalsSyncer = new RefrendPaymentTotalsSyncer();
        $this->admin        = User::factory()->create([
            'user_type'  => 'ADMIN',
            'active'     => true,
            'first_name' => 'Test',
            'last_name'  => 'Admin',
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function makeBecario(): User
    {
        return User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);
    }

    private function makeRefrend(int $userId, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => 1,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
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

    private function makeWithholding(int $userId, int $originRefrendId, array $overrides = []): ScholarshipWithholding
    {
        return ScholarshipWithholding::create(array_merge([
            'user_id'           => $userId,
            'origin_refrend_id' => $originRefrendId,
            'period_year'       => 2026,
            'period_month'      => 1,
            'withheld_amount'   => '300.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
            'cause'             => 'BAJO_PROMEDIO',
            'created_by_id'     => $this->admin->id,
        ], $overrides));
    }

    private function makePayment(int $withholdingId, int $appliedRefrendId, array $overrides = []): ScholarshipWithholdingPayment
    {
        return ScholarshipWithholdingPayment::create(array_merge([
            'withholding_id'     => $withholdingId,
            'applied_refrend_id' => $appliedRefrendId,
            'amount'             => '100.00',
            'created_by_id'      => $this->admin->id,
            'is_voided'          => false,
        ], $overrides));
    }

    // ── kind 1a: ledger_applied ─────────────────────────────────────────

    /** @test */
    public function ledger_applied_renders_contributing_withholdings_with_all_expected_fields(): void
    {
        $user           = $this->makeBecario();
        $originRefrend  = $this->makeRefrend($user->id, ['period_month' => 1]);
        $withholding    = $this->makeWithholding($user->id, $originRefrend->id, [
            'period_year' => 2026, 'period_month' => 1, 'withheld_amount' => '300.00', 'cause' => 'BAJO_PROMEDIO',
        ]);
        $currentRefrend = $this->makeRefrend($user->id, ['period_month' => 4]);
        $payment        = $this->makePayment($withholding->id, $currentRefrend->id, ['amount' => '300.00']);
        $withholding->recomputePaidAmount();

        $result = $this->breakdown->forRefrend($currentRefrend);

        $this->assertCount(1, $result['ledger_applied']);
        $entry = $result['ledger_applied'][0];
        $this->assertSame($payment->id, $entry['payment_id']);
        $this->assertSame($withholding->id, $entry['withholding_id']);
        $this->assertSame(2026, $entry['period_year']);
        $this->assertSame(1, $entry['period_month']);
        $this->assertSame('300.00', $entry['withheld_amount']);
        $this->assertSame('300.00', $entry['amount_applied_now']);
        $this->assertSame('BAJO_PROMEDIO', $entry['cause']);
        $this->assertSame('Test Admin', $entry['created_by']);
        $this->assertNotNull($entry['withheld_at']);
        $this->assertNotNull($entry['applied_at']);
    }

    /** @test */
    public function ledger_applied_includes_every_contributing_withholding_when_multiple_months_are_settled_in_one_payment(): void
    {
        $user           = $this->makeBecario();
        $janOrigin      = $this->makeRefrend($user->id, ['period_month' => 1]);
        $janWithholding = $this->makeWithholding($user->id, $janOrigin->id, ['period_year' => 2026, 'period_month' => 1, 'withheld_amount' => '300.00']);
        $marOrigin      = $this->makeRefrend($user->id, ['period_month' => 3]);
        $marWithholding = $this->makeWithholding($user->id, $marOrigin->id, ['period_year' => 2026, 'period_month' => 3, 'withheld_amount' => '150.00']);
        $currentRefrend = $this->makeRefrend($user->id, ['period_month' => 4]);

        $this->makePayment($janWithholding->id, $currentRefrend->id, ['amount' => '300.00']);
        $this->makePayment($marWithholding->id, $currentRefrend->id, ['amount' => '150.00']);
        $janWithholding->recomputePaidAmount();
        $marWithholding->recomputePaidAmount();

        $result = $this->breakdown->forRefrend($currentRefrend);

        $this->assertCount(2, $result['ledger_applied']);
        $withholdingIds = array_column($result['ledger_applied'], 'withholding_id');
        $this->assertContains($janWithholding->id, $withholdingIds);
        $this->assertContains($marWithholding->id, $withholdingIds);
    }

    /** @test */
    public function voided_payment_is_excluded_from_ledger_applied_and_total(): void
    {
        $user           = $this->makeBecario();
        $origin         = $this->makeRefrend($user->id, ['period_month' => 1]);
        $withholding    = $this->makeWithholding($user->id, $origin->id, ['withheld_amount' => '150.00']);
        $currentRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);

        $this->makePayment($withholding->id, $currentRefrend->id, ['amount' => '100.00']);
        $this->makePayment($withholding->id, $currentRefrend->id, [
            'amount' => '50.00', 'is_voided' => true, 'voided_at' => now(),
        ]);
        $withholding->recomputePaidAmount();

        $result = $this->breakdown->forRefrend($currentRefrend);

        $this->assertCount(1, $result['ledger_applied']);
        $this->assertSame('100.00', $result['ledger_applied'][0]['amount_applied_now']);
        $this->assertSame('100.00', $result['ledger_applied_total']);
    }

    /** @test */
    public function cancelled_withholding_status_does_not_filter_it_out_of_ledger_applied_and_total_still_reconciles(): void
    {
        $user           = $this->makeBecario();
        $origin         = $this->makeRefrend($user->id, ['period_month' => 1]);
        $withholding    = $this->makeWithholding($user->id, $origin->id, ['withheld_amount' => '200.00']);
        $currentRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);

        $this->makePayment($withholding->id, $currentRefrend->id, ['amount' => '200.00']);
        $withholding->recomputePaidAmount();
        $this->totalsSyncer->sync($currentRefrend);
        $currentRefrend->refresh();

        // Simulate a legacy/edge case: the withholding's own status later became
        // CANCELLED (e.g. administrative correction) without touching the
        // already-applied payment. The applied side must NOT filter on status.
        $withholding->update(['status' => 'CANCELLED']);

        $result = $this->breakdown->forRefrend($currentRefrend);

        $this->assertCount(1, $result['ledger_applied']);
        $this->assertSame('200.00', $result['ledger_applied'][0]['amount_applied_now']);
        $this->assertSame($currentRefrend->amount_pending_from_previous, $result['ledger_applied_total']);
    }

    // ── kind-1 reconciliation invariant ─────────────────────────────────

    /** @test */
    public function ledger_applied_total_reconciles_with_amount_pending_from_previous_for_partial_liquidation(): void
    {
        $user           = $this->makeBecario();
        $janOrigin      = $this->makeRefrend($user->id, ['period_month' => 1]);
        $janWithholding = $this->makeWithholding($user->id, $janOrigin->id, ['period_year' => 2026, 'period_month' => 1, 'withheld_amount' => '300.00']);
        $marOrigin      = $this->makeRefrend($user->id, ['period_month' => 3]);
        $marWithholding = $this->makeWithholding($user->id, $marOrigin->id, ['period_year' => 2026, 'period_month' => 3, 'withheld_amount' => '150.00']);
        $currentRefrend = $this->makeRefrend($user->id, ['period_month' => 4]);

        $this->makePayment($janWithholding->id, $currentRefrend->id, ['amount' => '300.00']);
        // Partial liquidation of March: only 100 of 150 owed is settled now.
        $this->makePayment($marWithholding->id, $currentRefrend->id, ['amount' => '100.00']);
        $janWithholding->recomputePaidAmount();
        $marWithholding->recomputePaidAmount();

        $this->totalsSyncer->sync($currentRefrend);
        $currentRefrend->refresh();

        $result = $this->breakdown->forRefrend($currentRefrend);

        $this->assertSame('400.00', $currentRefrend->amount_pending_from_previous);
        $this->assertSame($currentRefrend->amount_pending_from_previous, $result['ledger_applied_total']);
    }

    /** @test */
    public function ledger_applied_total_reconciles_with_amount_pending_from_previous_for_full_liquidation(): void
    {
        $user           = $this->makeBecario();
        $origin         = $this->makeRefrend($user->id, ['period_month' => 1]);
        $withholding    = $this->makeWithholding($user->id, $origin->id, ['withheld_amount' => '400.00']);
        $currentRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);

        $this->makePayment($withholding->id, $currentRefrend->id, ['amount' => '400.00']);
        $withholding->recomputePaidAmount();
        $this->totalsSyncer->sync($currentRefrend);
        $currentRefrend->refresh();

        $result = $this->breakdown->forRefrend($currentRefrend);

        $this->assertSame('400.00', $currentRefrend->amount_pending_from_previous);
        $this->assertSame($currentRefrend->amount_pending_from_previous, $result['ledger_applied_total']);
        $this->assertSame('0.00', $withholding->fresh()->remaining_amount);
    }

    /** @test */
    public function a_refrend_with_no_ledger_activity_has_empty_ledger_applied_and_zero_total(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertSame([], $result['ledger_applied']);
        $this->assertSame('0.00', $result['ledger_applied_total']);
    }

    // ── kind 1b: origin_withholding ──────────────────────────────────────

    /** @test */
    public function origin_withholding_returns_the_active_retention_this_refrend_generated(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $withholding = $this->makeWithholding($user->id, $refrend->id, [
            'period_year' => 2026, 'period_month' => 1, 'withheld_amount' => '250.00', 'status' => 'PENDING', 'cause' => 'OTRO',
        ]);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertNotNull($result['origin_withholding']);
        $this->assertSame($withholding->id, $result['origin_withholding']['withholding_id']);
        $this->assertSame('250.00', $result['origin_withholding']['withheld_amount']);
        $this->assertSame('PENDING', $result['origin_withholding']['status']);
        $this->assertSame('OTRO', $result['origin_withholding']['cause']);
        $this->assertSame('Test Admin', $result['origin_withholding']['created_by']);
    }

    /** @test */
    public function origin_withholding_is_null_when_the_originated_retention_was_cancelled(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id, ['period_month' => 1, 'resolution_type' => 'DESCUENTO_DEFINITIVO', 'discount_amount' => '120.00', 'discount_percentage' => '40.00', 'resolution_cause' => 'OTRO']);
        // Reclassification trap (design D2): the withholding this refrend
        // originally created as RETENIDA now sits CANCELLED after the refrend
        // was re-resolved to DESCUENTO_DEFINITIVO.
        $this->makeWithholding($user->id, $refrend->id, ['status' => 'CANCELLED']);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertNull($result['origin_withholding']);
        $this->assertNotNull($result['definitive_discount']);
    }

    /** @test */
    public function origin_withholding_is_null_when_the_refrend_never_originated_a_withholding(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertNull($result['origin_withholding']);
    }

    /** @test */
    public function origin_withholding_cancelled_exclusion_does_not_affect_a_different_refrends_ledger_applied_reconciliation(): void
    {
        // Refrend A: originated a withholding later CANCELLED via re-classification.
        $userA        = $this->makeBecario();
        $refrendA     = $this->makeRefrend($userA->id, ['period_month' => 1, 'resolution_type' => 'DESCUENTO_DEFINITIVO', 'discount_amount' => '80.00', 'discount_percentage' => '40.00']);
        $this->makeWithholding($userA->id, $refrendA->id, ['status' => 'CANCELLED']);

        // Refrend B/C: an entirely independent, real, reconciled ledger.
        $userB          = $this->makeBecario();
        $originB        = $this->makeRefrend($userB->id, ['period_month' => 1]);
        $withholdingB   = $this->makeWithholding($userB->id, $originB->id, ['withheld_amount' => '175.00']);
        $currentRefrendB = $this->makeRefrend($userB->id, ['period_month' => 2]);
        $this->makePayment($withholdingB->id, $currentRefrendB->id, ['amount' => '175.00']);
        $withholdingB->recomputePaidAmount();
        $this->totalsSyncer->sync($currentRefrendB);
        $currentRefrendB->refresh();

        $resultA = $this->breakdown->forRefrend($refrendA);
        $resultB = $this->breakdown->forRefrend($currentRefrendB);

        $this->assertNull($resultA['origin_withholding']);
        $this->assertSame($currentRefrendB->amount_pending_from_previous, $resultB['ledger_applied_total']);
        $this->assertCount(1, $resultB['ledger_applied']);
    }

    // ── kind 2: attendance_discounts ─────────────────────────────────────

    /** @test */
    public function attendance_discounts_returns_retardos_and_falta_injustificada_rows_with_no_amount_field(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);
        ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => 'RETARDOS',
            'discount_percentage'    => '10.00',
            'description'            => 'Tres retardos en el mes.',
        ]);
        ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => 'FALTA_INJUSTIFICADA',
            'discount_percentage'    => '15.00',
            'description'            => 'Una falta injustificada.',
        ]);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertCount(2, $result['attendance_discounts']);
        $types = array_column($result['attendance_discounts'], 'discount_type');
        $this->assertContains('RETARDOS', $types);
        $this->assertContains('FALTA_INJUSTIFICADA', $types);
        foreach ($result['attendance_discounts'] as $entry) {
            $this->assertArrayNotHasKey('amount', $entry);
            $this->assertSame(['id', 'discount_type', 'discount_percentage', 'description', 'created_at'], array_keys($entry));
        }
    }

    /** @test */
    public function attendance_discounts_excludes_other_discount_types(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);
        ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => 'PROMEDIO_BAJO',
            'discount_percentage'    => '20.00',
            'description'            => 'Promedio bajo el mínimo.',
        ]);
        ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => 'RETARDOS',
            'discount_percentage'    => '10.00',
            'description'            => 'Dos retardos.',
        ]);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertCount(1, $result['attendance_discounts']);
        $this->assertSame('RETARDOS', $result['attendance_discounts'][0]['discount_type']);
    }

    /** @test */
    public function attendance_discounts_is_empty_when_the_refrend_has_no_discount_rows(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertSame([], $result['attendance_discounts']);
    }

    // ── kind 3: definitive_discount ──────────────────────────────────────

    /** @test */
    public function definitive_discount_is_an_object_when_resolution_type_is_descuento_definitivo(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id, [
            'resolution_type'     => 'DESCUENTO_DEFINITIVO',
            'discount_amount'     => '400.00',
            'discount_percentage' => '40.00',
            'resolution_cause'    => 'BAJO_PROMEDIO',
        ]);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertIsArray($result['definitive_discount']);
        $this->assertArrayHasKey('discount_amount', $result['definitive_discount']);
        $this->assertSame('400.00', $result['definitive_discount']['discount_amount']);
        $this->assertSame('40.00', $result['definitive_discount']['discount_percentage']);
        $this->assertSame('BAJO_PROMEDIO', $result['definitive_discount']['resolution_cause']);
        $this->assertArrayNotHasKey(0, $result['definitive_discount']);
    }

    /** @test */
    public function definitive_discount_is_null_for_any_other_resolution_type(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id, ['resolution_type' => 'BECA_MES']);

        $result = $this->breakdown->forRefrend($refrend);

        $this->assertNull($result['definitive_discount']);
    }

    // ── multi-kind ────────────────────────────────────────────────────────

    /** @test */
    public function a_refrend_can_show_both_a_settled_withholding_and_an_attendance_discount_independently(): void
    {
        $user           = $this->makeBecario();
        $origin         = $this->makeRefrend($user->id, ['period_month' => 1]);
        $withholding    = $this->makeWithholding($user->id, $origin->id, ['withheld_amount' => '200.00']);
        $currentRefrend = $this->makeRefrend($user->id, ['period_month' => 2]);
        $this->makePayment($withholding->id, $currentRefrend->id, ['amount' => '200.00']);
        $withholding->recomputePaidAmount();
        ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $currentRefrend->id,
            'discount_type'          => 'RETARDOS',
            'discount_percentage'    => '10.00',
            'description'            => 'Dos retardos.',
        ]);

        $result = $this->breakdown->forRefrend($currentRefrend);

        $this->assertCount(1, $result['ledger_applied']);
        $this->assertCount(1, $result['attendance_discounts']);
        $this->assertNull($result['origin_withholding']);
        $this->assertNull($result['definitive_discount']);
    }
}
