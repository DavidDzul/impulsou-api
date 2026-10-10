<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use App\Services\Scholarship\TelmexCoverageRefrendSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-iu, design D9: sync attaches/detaches unpaid,
 * unlocked, unresolved refrends to match the coverage's current window,
 * leaves paid/locked rows untouched, and blocks (without writing anything)
 * when a would-change row already carries a resolution.
 */
class TelmexCoverageRefrendSyncTest extends TestCase
{
    use RefreshDatabase;

    private TelmexCoverageRefrendSync $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = $this->app->make(TelmexCoverageRefrendSync::class);
    }

    private function makeCoverage(array $overrides = []): TelmexCoverage
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        return TelmexCoverage::create(array_merge([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => '2026-02-01',
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
        ], $overrides));
    }

    private function makeRefrend(TelmexCoverage $coverage, int $year, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                        => $coverage->user_id,
            'period_year'                    => $year,
            'period_month'                   => $month,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::DRAFT->value,
            'workflow_status'                => 'DRAFT',
            'base_amount'                    => 0,
            'snapshot_gross_amount'           => 0,
            'snapshot_telmex_covered_amount' => 1950.0,
            'snapshot_telmex_coverage_id'    => null,
            'discount_percentage'            => 0,
            'discount_amount'                => 0,
            'final_amount'                   => 0,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => ScholarshipType::TELMEX->value,
        ], $overrides));
    }

    /** @test */
    public function sync_attaches_an_unpaid_unresolved_row_within_the_new_window(): void
    {
        $coverage = $this->makeCoverage();
        $refrend  = $this->makeRefrend($coverage, 2026, 2);

        $this->sync->sync($coverage);

        $fresh = $refrend->fresh();
        $this->assertSame($coverage->id, $fresh->snapshot_telmex_coverage_id);
        $this->assertSame('1950.00', $fresh->final_amount);
    }

    /** @test */
    public function sync_does_not_attach_a_row_before_start_period(): void
    {
        $coverage = $this->makeCoverage();
        $refrend  = $this->makeRefrend($coverage, 2026, 1);

        $this->sync->sync($coverage);

        $this->assertNull($refrend->fresh()->snapshot_telmex_coverage_id);
    }

    /** @test */
    public function sync_detaches_a_linked_unpaid_row_outside_a_new_end_period(): void
    {
        $coverage = $this->makeCoverage();
        $refrend  = $this->makeRefrend($coverage, 2026, 4, ['snapshot_telmex_coverage_id' => $coverage->id]);

        $coverage->update(['end_period' => '2026-02-01']);
        $this->sync->sync($coverage->fresh());

        $this->assertNull($refrend->fresh()->snapshot_telmex_coverage_id);
    }

    /** @test */
    public function sync_detaches_every_linked_unpaid_row_when_cancelada(): void
    {
        $coverage = $this->makeCoverage();
        $refrend  = $this->makeRefrend($coverage, 2026, 2, ['snapshot_telmex_coverage_id' => $coverage->id]);

        $coverage->update(['status' => 'CANCELADA']);
        $this->sync->sync($coverage->fresh());

        $this->assertNull($refrend->fresh()->snapshot_telmex_coverage_id);
    }

    /** @test */
    public function sync_leaves_a_paid_row_untouched_even_outside_the_new_window(): void
    {
        $coverage = $this->makeCoverage();
        $refrend  = $this->makeRefrend($coverage, 2026, 4, [
            'snapshot_telmex_coverage_id' => $coverage->id,
            'status'                      => RefrendStatus::PAID->value,
        ]);

        $coverage->update(['end_period' => '2026-02-01']);
        $this->sync->sync($coverage->fresh());

        $this->assertSame($coverage->id, $refrend->fresh()->snapshot_telmex_coverage_id);
    }

    /** @test */
    public function sync_leaves_a_locked_row_untouched(): void
    {
        $coverage = $this->makeCoverage();
        $refrend  = $this->makeRefrend($coverage, 2026, 4, [
            'snapshot_telmex_coverage_id' => $coverage->id,
            'locked_at'                   => now(),
        ]);

        $coverage->update(['end_period' => '2026-02-01']);
        $this->sync->sync($coverage->fresh());

        $this->assertSame($coverage->id, $refrend->fresh()->snapshot_telmex_coverage_id);
    }

    /** @test */
    public function sync_throws_and_writes_nothing_when_a_would_change_row_has_a_resolution(): void
    {
        $coverage   = $this->makeCoverage();
        $unaffected = $this->makeRefrend($coverage, 2026, 2);
        $blocked    = $this->makeRefrend($coverage, 2026, 3, ['resolution_type' => 'SIN_PAGO']);

        try {
            $this->sync->sync($coverage);
            $this->fail('Expected DomainException listing the blocked period.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('3/2026', $e->getMessage());
        }

        // Nothing written, including the unaffected row — the whole sync is
        // all-or-nothing within the caller's transaction.
        $this->assertNull($unaffected->fresh()->snapshot_telmex_coverage_id);
        $this->assertNull($blocked->fresh()->snapshot_telmex_coverage_id);
    }
}
