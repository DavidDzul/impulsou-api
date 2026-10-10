<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use App\Services\Scholarship\TelmexCoverageLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-iu, design D8: advanced/repaid/balance are always
 * derived from source-of-truth rows (paid covered refrends, non-voided
 * payments) — recomputeStatus() is the sole writer of status.
 */
class TelmexCoverageLedgerTest extends TestCase
{
    use RefreshDatabase;

    private TelmexCoverageLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = $this->app->make(TelmexCoverageLedger::class);
    }

    private function makeCoverage(array $overrides = []): TelmexCoverage
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        return TelmexCoverage::create(array_merge([
            'user_id'                       => $user->id,
            'scholarship_type_at_activation' => ScholarshipType::TELMEX->value,
            'start_period'                   => '2026-01-01',
            'end_period'                     => null,
            'status'                         => 'ACTIVA',
        ], $overrides));
    }

    private function makeCoveredRefrend(TelmexCoverage $coverage, int $year, int $month, float $covered, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                        => $coverage->user_id,
            'period_year'                    => $year,
            'period_month'                   => $month,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::DRAFT->value,
            'workflow_status'                => 'DRAFT',
            'base_amount'                    => 0,
            'snapshot_telmex_covered_amount' => $covered,
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'discount_percentage'            => 0,
            'discount_amount'                => 0,
            'final_amount'                   => $covered,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => ScholarshipType::TELMEX->value,
        ], $overrides));
    }

    /** @test */
    public function advanced_sums_only_paid_covered_refrends(): void
    {
        $coverage = $this->makeCoverage();
        $this->makeCoveredRefrend($coverage, 2026, 1, 1950.0, ['status' => RefrendStatus::PAID->value]);
        $this->makeCoveredRefrend($coverage, 2026, 2, 1950.0); // pending, not paid

        $this->assertSame(1950.0, $this->ledger->advanced($coverage));
    }

    /** @test */
    public function advanced_excludes_egreso_reticula_resolution(): void
    {
        $coverage = $this->makeCoverage();
        $this->makeCoveredRefrend($coverage, 2026, 1, 1950.0, [
            'status'          => RefrendStatus::PAID->value,
            'resolution_type' => ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA,
        ]);

        $this->assertSame(0.0, $this->ledger->advanced($coverage));
    }

    /** @test */
    public function repaid_sums_only_non_voided_payments(): void
    {
        $coverage = $this->makeCoverage();
        $coverage->payments()->create(['amount' => 500, 'paid_at' => '2026-02-01']);
        $coverage->payments()->create(['amount' => 300, 'paid_at' => '2026-03-01', 'is_voided' => true]);

        $this->assertSame(500.0, $this->ledger->repaid($coverage));
    }

    /** @test */
    public function balance_is_advanced_minus_repaid_floored_at_zero(): void
    {
        $coverage = $this->makeCoverage();
        $this->makeCoveredRefrend($coverage, 2026, 1, 1000.0, ['status' => RefrendStatus::PAID->value]);
        $coverage->payments()->create(['amount' => 1500, 'paid_at' => '2026-02-01']);

        $this->assertSame(0.0, $this->ledger->balance($coverage));
    }

    /** @test */
    public function has_paid_covered_month_is_false_with_no_paid_rows(): void
    {
        $coverage = $this->makeCoverage();
        $this->makeCoveredRefrend($coverage, 2026, 1, 1000.0); // pending

        $this->assertFalse($this->ledger->hasPaidCoveredMonth($coverage));
    }

    /** @test */
    public function recompute_status_stays_activa_when_end_period_is_null(): void
    {
        $coverage = $this->makeCoverage(['status' => 'EN_COBRO']);

        $result = $this->ledger->recomputeStatus($coverage);

        $this->assertSame('ACTIVA', $result->status);
    }

    /** @test */
    public function recompute_status_is_en_cobro_when_closed_and_not_settled(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-03-01']);
        $this->makeCoveredRefrend($coverage, 2026, 1, 1000.0, ['status' => RefrendStatus::PAID->value]);
        $coverage->payments()->create(['amount' => 400, 'paid_at' => '2026-04-01']);

        $result = $this->ledger->recomputeStatus($coverage);

        $this->assertSame('EN_COBRO', $result->status);
    }

    /** @test */
    public function recompute_status_is_liquidada_when_settled_with_no_unpaid_covered_rows(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-01-01']);
        $this->makeCoveredRefrend($coverage, 2026, 1, 1000.0, ['status' => RefrendStatus::PAID->value]);
        $coverage->payments()->create(['amount' => 1000, 'paid_at' => '2026-04-01']);

        $result = $this->ledger->recomputeStatus($coverage);

        $this->assertSame('LIQUIDADA', $result->status);
        $this->assertNotNull($result->settled_at);
    }

    /** @test */
    public function recompute_status_reverts_liquidada_to_en_cobro_when_a_settling_payment_is_voided(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-01-01']);
        $this->makeCoveredRefrend($coverage, 2026, 1, 1000.0, ['status' => RefrendStatus::PAID->value]);
        $payment = $coverage->payments()->create(['amount' => 1000, 'paid_at' => '2026-04-01']);
        $this->ledger->recomputeStatus($coverage);
        $this->assertSame('LIQUIDADA', $coverage->fresh()->status);

        $payment->update(['is_voided' => true]);
        $result = $this->ledger->recomputeStatus($coverage->fresh());

        $this->assertSame('EN_COBRO', $result->status);
        $this->assertNull($result->settled_at);
    }

    /** @test */
    public function recompute_status_never_changes_cancelada(): void
    {
        $coverage = $this->makeCoverage(['status' => 'CANCELADA', 'end_period' => '2026-01-01']);
        $coverage->payments()->create(['amount' => 99999, 'paid_at' => '2026-04-01']);

        $result = $this->ledger->recomputeStatus($coverage);

        $this->assertSame('CANCELADA', $result->status);
    }
}
