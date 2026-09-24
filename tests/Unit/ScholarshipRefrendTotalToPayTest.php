<?php

namespace Tests\Unit;

use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for ScholarshipRefrend::getTotalToPayAttribute() (design D6,
 * sdd/pago-adelantado, Work Unit 5 / PR5).
 *
 * This is the FIRST of the three lockstep total_to_pay formula sites (the
 * other two are RefrendBulkQueryServiceTest and PaymentBatchServiceTest).
 * advance_payment_amount is a NOT NULL decimal(10,2) column defaulting to 0
 * (migration 2026_09_24_000003), so both "unset" and "explicitly 0" must
 * leave the formula byte-identical to its pre-PR5 behavior.
 */
class ScholarshipRefrendTotalToPayTest extends TestCase
{
    use RefreshDatabase;

    private function makeRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 5,
            'base_amount'                  => 1000.00,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
        ], $overrides));
    }

    // ── Regression: advance_payment_amount null/0 leaves the formula unchanged ──

    /** @test */
    public function total_to_pay_is_unchanged_when_advance_payment_amount_defaults_to_zero(): void
    {
        // No explicit advance_payment_amount override — relies on the
        // column's NOT NULL DEFAULT 0 (migration 2026_09_24_000003), the
        // exact state of every pre-PR5 refrend row.
        $refrend = $this->makeRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 200.00,
        ]);

        $this->assertSame(
            '1200.00',
            $refrend->fresh()->total_to_pay,
            'total_to_pay must stay byte-identical to the pre-PR5 formula when advance_payment_amount is 0.'
        );
    }

    /** @test */
    public function total_to_pay_is_unchanged_with_refund_and_zero_advance_payment_amount(): void
    {
        $refrend = $this->makeRefrend([
            'final_amount'                 => 800.00,
            'amount_pending_from_previous' => 0,
            'refund_amount_from_previous'  => 50.00,
            'advance_payment_amount'       => 0,
        ]);

        $this->assertSame('850.00', $refrend->fresh()->total_to_pay);
    }

    // ── Correct-sum: non-zero advance_payment_amount case ──────────────────────

    /** @test */
    public function total_to_pay_includes_advance_payment_amount(): void
    {
        $refrend = $this->makeRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'advance_payment_amount'       => 3000.00,
        ]);

        $this->assertSame('4000.00', $refrend->fresh()->total_to_pay);
    }

    /** @test */
    public function total_to_pay_sums_all_four_terms_together(): void
    {
        $refrend = $this->makeRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 200.00,
            'refund_amount_from_previous'  => 50.00,
            'advance_payment_amount'       => 3000.00,
        ]);

        $this->assertSame('4250.00', $refrend->fresh()->total_to_pay);
    }
}
