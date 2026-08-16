<?php

namespace Tests\Unit;

use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layer-2 re-check (D2): RecordPaymentSituationAction independently rejects
 * out-of-window / rank-excluded withholding ids even when the request never
 * went through ScholarshipRefrendController's request validation (e.g. a
 * direct API call bypassing the frontend/controller layer). Mirrors the
 * feature-level 422 coverage in tests/Feature/WithholdingPartialAmountTest.php
 * but drives the Action directly, per design.
 */
class RecordPaymentSituationWindowTest extends TestCase
{
    use RefreshDatabase;

    private RecordPaymentSituationAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(RecordPaymentSituationAction::class);

        // ScholarshipLoggingService::log() resolves the actor via auth()->id();
        // an authenticated actor is required for the log write inside the
        // action's transaction to satisfy the NOT NULL performed_by_id column.
        $this->actingAs(User::factory()->create(['user_type' => 'ADMIN', 'active' => true]));
    }

    private function makeRefrend(int $userId, int $periodYear, int $periodMonth, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => $periodYear,
            'period_month'                 => $periodMonth,
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

    private function makeWithholdingForUser(int $userId, int $periodYear, int $periodMonth, float $withheld = 100.00): ScholarshipWithholding
    {
        $originRefrend = $this->makeRefrend($userId, $periodYear, $periodMonth, ['status' => 'WITHHELD']);

        return ScholarshipWithholding::create([
            'user_id'           => $userId,
            'origin_refrend_id' => $originRefrend->id,
            'period_year'       => $periodYear,
            'period_month'      => $periodMonth,
            'withheld_amount'   => number_format($withheld, 2, '.', ''),
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);
    }

    /** @test */
    public function rejects_an_out_of_window_withholding_id_bypassing_http_validation(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $payer   = $this->makeRefrend($becario->id, 2026, 8);
        $stale   = $this->makeWithholdingForUser($becario->id, 2026, 3, 400.00); // offset 5, out of window

        $this->expectException(\DomainException::class);

        $this->action->execute($payer, [
            'resolution_type'      => 'SIN_PAGO',
            'withholding_payments' => [
                ['withholding_id' => $stale->id, 'amount' => 100.00],
            ],
        ], $becario->id);
    }

    /** @test */
    public function rejects_the_3rd_in_window_id_excluded_by_top_2_rank(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $payer   = $this->makeRefrend($becario->id, 2026, 8);
        $this->makeWithholdingForUser($becario->id, 2026, 7, 100.00); // offset 1, payable (rank 1)
        $this->makeWithholdingForUser($becario->id, 2026, 6, 100.00); // offset 2, payable (rank 2)
        $third = $this->makeWithholdingForUser($becario->id, 2026, 5, 100.00); // offset 3, in-window but rank 3rd

        $this->expectException(\DomainException::class);

        $this->action->execute($payer, [
            'resolution_type'      => 'SIN_PAGO',
            'withholding_payments' => [
                ['withholding_id' => $third->id, 'amount' => 100.00],
            ],
        ], $becario->id);
    }

    /** @test */
    public function persists_normally_when_both_ids_are_payable(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $payer   = $this->makeRefrend($becario->id, 2026, 8);
        $first   = $this->makeWithholdingForUser($becario->id, 2026, 7, 100.00); // offset 1, payable
        $second  = $this->makeWithholdingForUser($becario->id, 2026, 6, 100.00); // offset 2, payable

        $result = $this->action->execute($payer, [
            'resolution_type'      => 'SIN_PAGO',
            'withholding_payments' => [
                ['withholding_id' => $first->id, 'amount' => 100.00],
                ['withholding_id' => $second->id, 'amount' => 100.00],
            ],
        ], $becario->id);

        $this->assertSame(RefrendStatus::DRAFT, $result->status);
        $this->assertSame('0.00', $result->final_amount);
        $this->assertSame('100.00', $first->fresh()->paid_amount);
        $this->assertSame('100.00', $second->fresh()->paid_amount);
    }
}
