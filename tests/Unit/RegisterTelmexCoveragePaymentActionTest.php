<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RegisterTelmexCoveragePaymentAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTelmexCoveragePaymentActionTest extends TestCase
{
    use RefreshDatabase;

    private RegisterTelmexCoveragePaymentAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(RegisterTelmexCoveragePaymentAction::class);
    }

    private function makeCoverageWithAdvance(float $advanced, array $overrides = []): TelmexCoverage
    {
        $user     = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $coverage = TelmexCoverage::create(array_merge([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => '2026-01-01',
            'end_period'                      => '2026-01-01',
            'status'                          => 'EN_COBRO',
        ], $overrides));

        ScholarshipRefrend::create([
            'user_id'                        => $coverage->user_id,
            'period_year'                    => 2026,
            'period_month'                   => 1,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::PAID->value,
            'workflow_status'                => 'PAID',
            'base_amount'                    => 0,
            'snapshot_telmex_covered_amount' => $advanced,
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'final_amount'                   => $advanced,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => 'TELMEX',
        ]);

        return $coverage;
    }

    /** @test */
    public function registers_a_partial_repayment(): void
    {
        $coverage = $this->makeCoverageWithAdvance(1000.0);
        $staff    = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $payment = $this->action->execute($coverage, ['amount' => 400, 'paid_at' => '2026-02-01'], $staff->id);

        $this->assertSame('400.00', $payment->amount);
        $this->assertSame($staff->id, $payment->created_by_id);
        $this->assertSame('EN_COBRO', $coverage->fresh()->status);
    }

    /** @test */
    public function settling_the_full_balance_liquidates_the_coverage(): void
    {
        $coverage = $this->makeCoverageWithAdvance(1000.0);
        $staff    = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $this->action->execute($coverage, ['amount' => 1000, 'paid_at' => '2026-02-01'], $staff->id);

        $this->assertSame('LIQUIDADA', $coverage->fresh()->status);
    }

    /** @test */
    public function rejects_a_zero_amount(): void
    {
        $coverage = $this->makeCoverageWithAdvance(1000.0);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, ['amount' => 0], 7);
    }

    /** @test */
    public function rejects_an_amount_exceeding_the_balance(): void
    {
        $coverage = $this->makeCoverageWithAdvance(500.0);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, ['amount' => 600], 7);
    }

    /** @test */
    public function rejects_a_payment_on_a_cancelada_coverage(): void
    {
        $coverage = $this->makeCoverageWithAdvance(500.0, ['status' => 'CANCELADA']);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, ['amount' => 100], 7);
    }

    /** @test */
    public function rejects_a_payment_on_a_liquidada_coverage(): void
    {
        $coverage = $this->makeCoverageWithAdvance(500.0, ['status' => 'LIQUIDADA']);

        $this->expectException(\DomainException::class);
        $this->action->execute($coverage, ['amount' => 100], 7);
    }
}
