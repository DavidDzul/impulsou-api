<?php

namespace Tests\Unit;

use App\Actions\Scholarship\ApproveRefrendAction;
use App\Actions\Scholarship\BulkApproveAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * pagos-adelantados-doble-pago F1: approving a refrend whose month was already
 * paid in advance would pay it twice, so it must be rejected (any link status).
 */
class ApproveRefrendActionTest extends TestCase
{
    use RefreshDatabase;

    private const ADVANCE_MESSAGE = 'Mes pagado por adelantado; regístrelo individualmente.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);
    }

    private function makeRefrend(int $month = 6): ScholarshipRefrend
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        return ScholarshipRefrend::create([
            'user_id'                      => $user->id,
            'period_year'                  => 2027,
            'period_month'                 => $month,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);
    }

    private function linkAdvanceMonth(ScholarshipRefrend $refrend, string $status = 'PENDING'): ScholarshipAdvancePaymentMonth
    {
        return ScholarshipAdvancePaymentMonth::factory()->create([
            'user_id'      => $refrend->user_id,
            'period_year'  => $refrend->period_year,
            'period_month' => $refrend->period_month,
            'amount'       => '1000.00',
            'refrend_id'   => $refrend->id,
            'status'       => $status,
        ]);
    }

    /** @test */
    public function approving_a_non_advance_refrend_still_works(): void
    {
        $refrend = $this->makeRefrend();

        $result = $this->app->make(ApproveRefrendAction::class)->execute($refrend, $this->admin->id);

        $this->assertSame('LISTO_PARA_PAGO', $result->workflow_status);
    }

    /**
     * @test
     * @dataProvider advanceStatuses
     */
    public function approving_an_advance_linked_refrend_is_rejected_whatever_the_link_status(string $status): void
    {
        $refrend      = $this->makeRefrend();
        $advanceMonth = $this->linkAdvanceMonth($refrend, $status);

        try {
            $this->app->make(ApproveRefrendAction::class)->execute($refrend, $this->admin->id);
            $this->fail('Expected DomainException was not thrown.');
        } catch (\DomainException $e) {
            $this->assertSame(self::ADVANCE_MESSAGE, $e->getMessage());
        }

        $this->assertSame('DRAFT', $refrend->fresh()->workflow_status);
        $this->assertSame($status, $advanceMonth->fresh()->status);
    }

    public function advanceStatuses(): array
    {
        return [['PENDING'], ['REACHED'], ['OVERRIDDEN']];
    }

    /** @test */
    public function bulk_approve_skips_the_advance_row_and_approves_the_rest(): void
    {
        $normalA = $this->makeRefrend(1);
        $advance = $this->makeRefrend(2);
        $normalC = $this->makeRefrend(3);
        $this->linkAdvanceMonth($advance);

        $result = $this->app->make(BulkApproveAction::class)
            ->execute([$normalA->id, $advance->id, $normalC->id], $this->admin->id);

        $this->assertSame(2, $result['approved']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame($advance->id, $result['errors'][0]['id']);
        $this->assertSame(self::ADVANCE_MESSAGE, $result['errors'][0]['reason']);
        $this->assertSame('DRAFT', $advance->fresh()->workflow_status);
    }
}
