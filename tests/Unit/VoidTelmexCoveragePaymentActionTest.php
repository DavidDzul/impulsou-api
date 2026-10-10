<?php

namespace Tests\Unit;

use App\Actions\Scholarship\VoidTelmexCoveragePaymentAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoidTelmexCoveragePaymentActionTest extends TestCase
{
    use RefreshDatabase;

    private VoidTelmexCoveragePaymentAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(VoidTelmexCoveragePaymentAction::class);
    }

    private function makeSettledCoverage(): array
    {
        $user     = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $coverage = TelmexCoverage::create([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => '2026-01-01',
            'end_period'                      => '2026-01-01',
            'status'                          => 'LIQUIDADA',
            'settled_at'                      => now(),
        ]);

        ScholarshipRefrend::create([
            'user_id'                        => $coverage->user_id,
            'period_year'                    => 2026,
            'period_month'                   => 1,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::PAID->value,
            'workflow_status'                => 'PAID',
            'base_amount'                    => 0,
            'snapshot_telmex_covered_amount' => 1000.0,
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'final_amount'                   => 1000.0,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => 'TELMEX',
        ]);

        $payment = $coverage->payments()->create(['amount' => 1000, 'paid_at' => '2026-02-01']);

        return [$coverage, $payment];
    }

    /** @test */
    public function voiding_the_settling_payment_reverts_liquidada_to_en_cobro(): void
    {
        [$coverage, $payment] = $this->makeSettledCoverage();
        $staff                = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $result = $this->action->execute($payment, 'Depósito duplicado por error de captura.', $staff->id);

        $this->assertTrue($result->is_voided);
        $this->assertSame($staff->id, $result->voided_by_id);
        $this->assertSame('EN_COBRO', $coverage->fresh()->status);
    }

    /** @test */
    public function rejects_an_empty_reason(): void
    {
        [, $payment] = $this->makeSettledCoverage();

        $this->expectException(\DomainException::class);
        $this->action->execute($payment, '   ', 3);
    }

    /** @test */
    public function rejects_voiding_an_already_voided_payment(): void
    {
        [, $payment] = $this->makeSettledCoverage();
        $payment->update(['is_voided' => true]);

        $this->expectException(\DomainException::class);
        $this->action->execute($payment, 'Motivo válido.', 3);
    }

    /** @test */
    public function rejects_voiding_a_payment_on_a_cancelada_coverage(): void
    {
        [$coverage, $payment] = $this->makeSettledCoverage();
        $coverage->update(['status' => 'CANCELADA']);

        $this->expectException(\DomainException::class);
        $this->action->execute($payment, 'Motivo válido.', 3);
    }
}
