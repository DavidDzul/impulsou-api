<?php

namespace Tests\Unit;

use App\Actions\Scholarship\CancelTelmexCoverageAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelTelmexCoverageActionTest extends TestCase
{
    use RefreshDatabase;

    private CancelTelmexCoverageAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(CancelTelmexCoverageAction::class);
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
    public function cancels_with_a_valid_reason(): void
    {
        $coverage = $this->makeCoverage();
        $staff    = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $result = $this->action->execute($coverage, 'El becario abandonó el programa académico.', $staff->id);

        $this->assertSame('CANCELADA', $result->status);
        $this->assertSame($staff->id, $result->cancelled_by_id);
        $this->assertNotNull($result->cancelled_at);
    }

    /** @test */
    public function rejects_a_reason_shorter_than_ten_characters(): void
    {
        $coverage = $this->makeCoverage();

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, 'corto', 5);
    }

    /** @test */
    public function rejects_an_empty_reason(): void
    {
        $coverage = $this->makeCoverage();

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, '', 5);
    }

    /** @test */
    public function rejects_cancelling_an_already_cancelled_coverage(): void
    {
        $coverage = $this->makeCoverage(['status' => 'CANCELADA']);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, 'Motivo valido con mas de diez caracteres.', 5);
    }

    /** @test */
    public function rejects_cancelling_a_liquidada_coverage(): void
    {
        $coverage = $this->makeCoverage(['status' => 'LIQUIDADA']);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, 'Motivo valido con mas de diez caracteres.', 5);
    }

    /** @test */
    public function detaches_unpaid_covered_rows_but_keeps_paid_history(): void
    {
        $coverage = $this->makeCoverage();
        $pending  = ScholarshipRefrend::create($this->refrendData($coverage, 2026, 2));
        $paid     = ScholarshipRefrend::create(array_merge(
            $this->refrendData($coverage, 2026, 1),
            ['status' => RefrendStatus::PAID->value]
        ));

        $staff = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->action->execute($coverage, 'El becario abandonó el programa académico.', $staff->id);

        $this->assertNull($pending->fresh()->snapshot_telmex_coverage_id);
        $this->assertSame($coverage->id, $paid->fresh()->snapshot_telmex_coverage_id);
    }

    private function refrendData(TelmexCoverage $coverage, int $year, int $month): array
    {
        return [
            'user_id'                        => $coverage->user_id,
            'period_year'                    => $year,
            'period_month'                   => $month,
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
        ];
    }
}
