<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Enums\WithholdingCancellationReason;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/retenciones-pendientes, PR1 (task 1.1): audit fields on
 * scholarship_withholdings (cancelled_at, cancellation_reason, cancelled_by_id).
 */
class ScholarshipWithholdingCancellationFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function makeWithholding(array $overrides = []): ScholarshipWithholding
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $refrend = ScholarshipRefrend::create([
            'user_id'                      => $user->id,
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
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);

        return ScholarshipWithholding::create(array_merge([
            'user_id'           => $user->id,
            'origin_refrend_id' => $refrend->id,
            'period_year'       => 2026,
            'period_month'      => 1,
            'withheld_amount'   => '300.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ], $overrides));
    }

    /** @test */
    public function the_table_has_the_three_nullable_audit_columns(): void
    {
        foreach (['cancelled_at', 'cancellation_reason', 'cancelled_by_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_withholdings', $column), $column);
        }
    }

    /** @test */
    public function the_reason_enum_exposes_the_four_values_within_the_column_width(): void
    {
        $this->assertSame(
            ['CYCLE_CUT', 'EGRESO_RETICULA', 'USER_DEACTIVATED', 'RECLASSIFIED'],
            WithholdingCancellationReason::values()
        );

        foreach (WithholdingCancellationReason::values() as $value) {
            $this->assertLessThanOrEqual(20, strlen($value));
        }
    }

    /** @test */
    public function cancellation_fields_are_fillable_and_cast(): void
    {
        $actor = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $w = $this->makeWithholding([
            'status'              => 'CANCELLED',
            'cancelled_at'        => '2026-10-30 10:00:00',
            'cancellation_reason' => WithholdingCancellationReason::CYCLE_CUT->value,
            'cancelled_by_id'     => $actor->id,
        ])->fresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $w->cancelled_at);
        $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $w->cancellation_reason);
        $this->assertSame($actor->id, $w->cancelledBy->id);
    }

    /** @test */
    public function a_legacy_cancelled_row_with_null_reason_loads_without_error(): void
    {
        $w = $this->makeWithholding(['status' => 'CANCELLED'])->fresh();

        $this->assertSame('CANCELLED', $w->status);
        $this->assertNull($w->cancelled_at);
        $this->assertNull($w->cancellation_reason);
        $this->assertNull($w->cancelledBy);
        $this->assertArrayHasKey('cancellation_reason', $w->toArray());
    }
}
