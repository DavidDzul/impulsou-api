<?php

namespace Tests\Unit;

use App\Actions\Scholarship\ReactivateTelmexCoverageAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** sdd/telmex-cobertura-iu, decisions-2 #1921 dec3. */
class ReactivateTelmexCoverageActionTest extends TestCase
{
    use RefreshDatabase;

    private ReactivateTelmexCoverageAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(ReactivateTelmexCoverageAction::class);
    }

    private function makeCoverage(array $overrides = []): TelmexCoverage
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        return TelmexCoverage::create(array_merge([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => '2026-01-01',
            'end_period'                      => null,
            'status'                          => 'CANCELADA',
            'cancel_reason'                   => 'Activado por error inicialmente.',
            'cancelled_at'                    => now(),
        ], $overrides));
    }

    /** @test */
    public function reactivates_a_cancelada_coverage_with_no_paid_covered_month(): void
    {
        $coverage = $this->makeCoverage();

        $result = $this->action->execute($coverage, 1);

        $this->assertSame('ACTIVA', $result->status);
        $this->assertNull($result->cancel_reason);
        $this->assertNull($result->cancelled_at);
    }

    /** @test */
    public function rejects_reactivation_when_a_covered_month_was_already_paid(): void
    {
        $coverage = $this->makeCoverage();
        ScholarshipRefrend::create([
            'user_id'                        => $coverage->user_id,
            'period_year'                    => 2026,
            'period_month'                   => 1,
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
        $this->action->execute($coverage, 1);
    }

    /** @test */
    public function rejects_reactivating_a_non_cancelada_coverage(): void
    {
        $coverage = $this->makeCoverage(['status' => 'ACTIVA', 'cancel_reason' => null, 'cancelled_at' => null]);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, 1);
    }
}
