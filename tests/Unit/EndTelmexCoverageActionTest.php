<?php

namespace Tests\Unit;

use App\Actions\Scholarship\EndTelmexCoverageAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndTelmexCoverageActionTest extends TestCase
{
    use RefreshDatabase;

    private EndTelmexCoverageAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(EndTelmexCoverageAction::class);
    }

    private function makeCoverage(array $overrides = []): TelmexCoverage
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        return TelmexCoverage::create(array_merge([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => '2026-01-01',
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
        ], $overrides));
    }

    /** @test */
    public function sets_end_period_to_one_month_before_telmex_start(): void
    {
        $coverage = $this->makeCoverage();

        $result = $this->action->execute($coverage, ['telmex_start_period' => '2026-04-01'], 1);

        $this->assertSame('2026-03-01', $result->end_period->toDateString());
        $this->assertSame(1, $result->ended_by_id);
        $this->assertSame('EN_COBRO', $result->status);
    }

    /** @test */
    public function rejects_an_end_before_the_coverage_start(): void
    {
        $coverage = $this->makeCoverage(['start_period' => '2026-03-01']);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, ['telmex_start_period' => '2026-02-01'], 1);
    }

    /** @test */
    public function rejects_an_end_before_the_last_paid_covered_month(): void
    {
        $coverage = $this->makeCoverage();
        ScholarshipRefrend::create([
            'user_id'                        => $coverage->user_id,
            'period_year'                    => 2026,
            'period_month'                   => 5,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::PAID->value,
            'workflow_status'                => 'PAID',
            'base_amount'                    => 0,
            'snapshot_telmex_covered_amount' => 1950.0,
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'final_amount'                   => 1950.0,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => 'TELMEX',
        ]);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, ['telmex_start_period' => '2026-04-01'], 1);
    }

    /** @test */
    public function rejects_when_coverage_is_already_cancelada(): void
    {
        $coverage = $this->makeCoverage(['status' => 'CANCELADA']);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, ['telmex_start_period' => '2026-04-01'], 1);
    }

    /** @test */
    public function detaches_unpaid_refrends_outside_the_new_window(): void
    {
        $coverage = $this->makeCoverage();
        $refrend  = ScholarshipRefrend::create([
            'user_id'                        => $coverage->user_id,
            'period_year'                    => 2026,
            'period_month'                   => 4,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::DRAFT->value,
            'workflow_status'                => 'DRAFT',
            'base_amount'                    => 0,
            'snapshot_gross_amount'           => 0,
            'snapshot_telmex_covered_amount' => 1950.0,
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'discount_percentage'            => 0,
            'discount_amount'                => 0,
            'final_amount'                   => 1950.0,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => 'TELMEX',
        ]);

        $this->action->execute($coverage, ['telmex_start_period' => '2026-03-01'], 1);

        $this->assertNull($refrend->fresh()->snapshot_telmex_coverage_id);
    }
}
