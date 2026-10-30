<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Exceptions\AdvanceDivergenceRequiredException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\AdvancePaymentReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S5 (spec R17 / S36): a CANCELLED advance month
 * is skipped by the reconciler BEFORE the divergence check.
 */
class AdvancePaymentReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private function monthWithStatus(string $status, string $finalAmount): array
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $make = fn (int $month, array $extra = []) => ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => $month,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $extra));

        $origin = $make(3, ['advance_payment_amount' => '2500.00']);
        $cut    = $make(4, ['final_amount' => $finalAmount]);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 3,
            'months_count'        => 1,
            'total_amount'        => '2500.00',
        ]);

        $month = ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header->id,
            'user_id'            => $user->id,
            'period_year'        => 2026,
            'period_month'       => 4,
            'amount'             => '2500.00',
            'refrend_id'         => $cut->id,
            'status'             => $status,
        ]);

        return [$cut, $month];
    }

    /** @test */
    public function a_cancelled_month_is_left_untouched_even_when_the_refrend_would_diverge(): void
    {
        [$cut, $month] = $this->monthWithStatus(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, '1000.00');

        $this->app->make(AdvancePaymentReconciler::class)->reconcile($cut, 'BECA_MES', null);

        $month->refresh();
        $this->assertSame('CANCELLED', $month->status);
        $this->assertNull($month->reached_at);
        $this->assertNull($month->settled_resolution_type);
    }

    /** @test */
    public function a_cancelled_month_is_not_re_marked_reached_for_a_zero_amount_refrend(): void
    {
        [$cut, $month] = $this->monthWithStatus(ScholarshipAdvancePaymentMonth::STATUS_CANCELLED, '0.00');

        $this->app->make(AdvancePaymentReconciler::class)->reconcile($cut, 'BAJA_DEFINITIVA', null);

        $this->assertSame('CANCELLED', $month->fresh()->status);
    }

    /** @test */
    public function a_pending_month_still_requires_a_divergence_reason(): void
    {
        [$cut] = $this->monthWithStatus(ScholarshipAdvancePaymentMonth::STATUS_PENDING, '1000.00');

        $this->expectException(AdvanceDivergenceRequiredException::class);

        $this->app->make(AdvancePaymentReconciler::class)->reconcile($cut, 'BECA_MES', null);
    }
}
