<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use App\Services\Scholarship\BackfillWithholdingLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the backfill logic (extracted from the migration into
 * BackfillWithholdingLedgerService for testability) against a seeded
 * multi-period dataset, verifying it reproduces the same net pending debt
 * the old GenerateMonthlyRefrendsService::calculatePendingCarryover() used
 * to compute, and that every paid_amount > 0 row gets a synthetic child.
 */
class BackfillWithholdingLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackfillWithholdingLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(BackfillWithholdingLedgerService::class);
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
    public function backfill_creates_a_ledger_row_per_historical_retenida_refrend(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $this->makeRefrend($user->id, [
            'period_month' => 1, 'resolution_type' => 'RETENIDA',
            'status' => RefrendStatus::WITHHELD->value, 'discount_amount' => 300.00, 'final_amount' => 700.00,
        ]);
        $this->makeRefrend($user->id, [
            'period_month' => 3, 'resolution_type' => 'RETENIDA',
            'status' => RefrendStatus::WITHHELD->value, 'discount_amount' => 150.00, 'final_amount' => 850.00,
        ]);

        $this->service->run();

        $this->assertSame(2, ScholarshipWithholding::where('user_id', $user->id)->count());
    }

    /** @test */
    public function backfill_includes_legacy_withheld_rows_without_resolution_type(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $this->makeRefrend($user->id, [
            'period_month' => 2, 'resolution_type' => null,
            'status' => RefrendStatus::WITHHELD->value, 'base_amount' => 500.00,
            'discount_amount' => 0, 'final_amount' => 0,
        ]);

        $this->service->run();

        $ledger = ScholarshipWithholding::where('user_id', $user->id)->first();
        $this->assertNotNull($ledger);
        $this->assertSame('500.00', $ledger->withheld_amount);
    }

    /** @test */
    public function backfill_reproduces_net_pending_debt_via_fifo_with_synthetic_children(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        // Three historical retentions: jan $300, mar $150, apr $200 = $650 total withheld.
        $jan = $this->makeRefrend($user->id, [
            'period_month' => 1, 'resolution_type' => 'RETENIDA',
            'status' => RefrendStatus::WITHHELD->value, 'discount_amount' => 300.00, 'final_amount' => 700.00,
        ]);
        $mar = $this->makeRefrend($user->id, [
            'period_month' => 3, 'resolution_type' => 'RETENIDA',
            'status' => RefrendStatus::WITHHELD->value, 'discount_amount' => 150.00, 'final_amount' => 850.00,
        ]);
        $this->makeRefrend($user->id, [
            'period_month' => 4, 'resolution_type' => 'RETENIDA',
            'status' => RefrendStatus::WITHHELD->value, 'discount_amount' => 200.00, 'final_amount' => 800.00,
        ]);

        // A later PAID refrend already carried $400 of "already covered" carryover
        // under the old scalar mechanism (replicates calculatePendingCarryover's
        // "alreadyCovered" pool) — should cover jan ($300) fully and mar partially ($100).
        $paidRefrend = $this->makeRefrend($user->id, [
            'period_month' => 5, 'status' => RefrendStatus::PAID->value,
            'workflow_status' => 'CLOSED', 'amount_pending_from_previous' => 400.00,
        ]);

        // Still-open refrend whose amount_pending_from_previous was autocalculated
        // by the old method but never actually liquidated — must be zeroed.
        $openRefrend = $this->makeRefrend($user->id, [
            'period_month' => 6, 'workflow_status' => 'DRAFT', 'amount_pending_from_previous' => 650.00,
        ]);

        $this->service->run();

        $janLedger = ScholarshipWithholding::where('origin_refrend_id', $jan->id)->first();
        $marLedger = ScholarshipWithholding::where('origin_refrend_id', $mar->id)->first();

        $this->assertSame('300.00', $janLedger->paid_amount);
        $this->assertSame('PAID', $janLedger->status);

        $this->assertSame('100.00', $marLedger->paid_amount);
        $this->assertSame('PENDING', $marLedger->status);
        $this->assertSame('50.00', $marLedger->remaining_amount);

        // Every synthetic child from this FIFO application must be attributed
        // to the closed refrend that carried the covered amount.
        $janChild = ScholarshipWithholdingPayment::where('withholding_id', $janLedger->id)->first();
        $this->assertSame($paidRefrend->id, $janChild->applied_refrend_id);
        $this->assertNull($janChild->created_by_id);

        // Net pending debt across the ledger matches the old scalar model:
        // total withheld (650) - already covered (400) = 250.
        $netPending = ScholarshipWithholding::where('user_id', $user->id)
            ->get()
            ->sum(fn ($w) => (float) $w->remaining_amount);
        $this->assertEqualsWithDelta(250.0, $netPending, 0.01);

        $openRefrend->refresh();
        $this->assertSame('0.00', $openRefrend->amount_pending_from_previous);
    }

    /** @test */
    public function every_settled_or_partial_ledger_row_has_a_matching_synthetic_child_sum(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $jan = $this->makeRefrend($user->id, [
            'period_month' => 1, 'resolution_type' => 'RETENIDA',
            'status' => RefrendStatus::WITHHELD->value, 'discount_amount' => 300.00, 'final_amount' => 700.00,
        ]);
        $this->makeRefrend($user->id, [
            'period_month' => 5, 'status' => RefrendStatus::PAID->value,
            'workflow_status' => 'CLOSED', 'amount_pending_from_previous' => 300.00,
        ]);

        $this->service->run();

        // Invariant from design §8.6: paid_amount must equal SUM(active children).
        $ledgers = ScholarshipWithholding::where('user_id', $user->id)->get();
        foreach ($ledgers as $ledger) {
            $childSum = round((float) ScholarshipWithholdingPayment::where('withholding_id', $ledger->id)
                ->where('is_voided', false)
                ->sum('amount'), 2);
            $this->assertEqualsWithDelta((float) $ledger->paid_amount, $childSum, 0.01);
        }

        $this->assertGreaterThan(0, ScholarshipWithholdingPayment::where('withholding_id', ScholarshipWithholding::where('origin_refrend_id', $jan->id)->value('id'))->count());
    }

    /** @test */
    public function revert_synthetic_removes_only_backfill_inserted_rows(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $this->makeRefrend($user->id, [
            'period_month' => 1, 'resolution_type' => 'RETENIDA',
            'status' => RefrendStatus::WITHHELD->value, 'discount_amount' => 300.00, 'final_amount' => 700.00,
        ]);
        $this->makeRefrend($user->id, [
            'period_month' => 5, 'status' => RefrendStatus::PAID->value,
            'workflow_status' => 'CLOSED', 'amount_pending_from_previous' => 300.00,
        ]);

        $this->service->run();
        $this->assertGreaterThan(0, ScholarshipWithholding::count());

        $this->service->revertSynthetic();

        $this->assertSame(0, ScholarshipWithholding::count());
        $this->assertSame(0, ScholarshipWithholdingPayment::count());
    }
}
