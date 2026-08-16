<?php

namespace Tests\Unit;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use App\Services\Scholarship\PayableWithholdingWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the pure month-math/ranking logic (absoluteMonth, isWithinWindow,
 * selectPayable) in isolation, plus the single DB-touching method
 * (payableIdsForUser) against seeded ledger rows.
 */
class PayableWithholdingWindowTest extends TestCase
{
    use RefreshDatabase;

    // ---- absoluteMonth ----------------------------------------------------

    /** @test */
    public function absolute_month_is_year_times_twelve_plus_month(): void
    {
        $this->assertSame(2026 * 12 + 8, PayableWithholdingWindow::absoluteMonth(2026, 8));
    }

    /** @test */
    public function absolute_month_orders_correctly_across_a_december_to_january_rollover(): void
    {
        $dec = PayableWithholdingWindow::absoluteMonth(2025, 12);
        $jan = PayableWithholdingWindow::absoluteMonth(2026, 1);

        $this->assertSame(1, $jan - $dec);
    }

    // ---- isWithinWindow -----------------------------------------------------

    /** @test */
    public function offset_zero_one_and_three_months_back_are_within_window(): void
    {
        // current = 2026-08
        $this->assertTrue(PayableWithholdingWindow::isWithinWindow(2026, 8, 2026, 8)); // offset 0
        $this->assertTrue(PayableWithholdingWindow::isWithinWindow(2026, 8, 2026, 7)); // offset 1
        $this->assertTrue(PayableWithholdingWindow::isWithinWindow(2026, 8, 2026, 5)); // offset 3
    }

    /** @test */
    public function offset_four_or_more_months_back_is_excluded(): void
    {
        $this->assertFalse(PayableWithholdingWindow::isWithinWindow(2026, 8, 2026, 4)); // offset 4
        $this->assertFalse(PayableWithholdingWindow::isWithinWindow(2026, 8, 2025, 1)); // offset 7
    }

    /** @test */
    public function offset_is_rollover_safe_across_a_year_boundary(): void
    {
        // current = 2026-01, origin = 2025-10 -> offset 3, still in window.
        $this->assertTrue(PayableWithholdingWindow::isWithinWindow(2026, 1, 2025, 10));
        // current = 2026-01, origin = 2025-09 -> offset 4, out of window.
        $this->assertFalse(PayableWithholdingWindow::isWithinWindow(2026, 1, 2025, 9));
    }

    // ---- selectPayable (pure) ------------------------------------------------

    /** @test */
    public function select_payable_returns_empty_collection_for_empty_input(): void
    {
        $result = PayableWithholdingWindow::selectPayable([], 2026, 8);

        $this->assertTrue($result->isEmpty());
    }

    /** @test */
    public function select_payable_picks_the_two_most_recent_when_three_fall_in_window(): void
    {
        // All three within the 3-month window of 2026-08 (offsets 1, 2, 3).
        $rows = [
            ['id' => 1, 'period_year' => 2026, 'period_month' => 7], // offset 1
            ['id' => 2, 'period_year' => 2026, 'period_month' => 6], // offset 2
            ['id' => 3, 'period_year' => 2026, 'period_month' => 5], // offset 3 (oldest in window)
        ];

        $result = PayableWithholdingWindow::selectPayable($rows, 2026, 8);

        $this->assertSame([1, 2], $result->pluck('id')->all());
    }

    /** @test */
    public function select_payable_excludes_rows_outside_the_window_even_when_fewer_than_two_remain(): void
    {
        $rows = [
            ['id' => 1, 'period_year' => 2026, 'period_month' => 7],  // offset 1 -> in
            ['id' => 2, 'period_year' => 2026, 'period_month' => 3],  // offset 5 -> out
        ];

        $result = PayableWithholdingWindow::selectPayable($rows, 2026, 8);

        $this->assertSame([1], $result->pluck('id')->all());
    }

    /** @test */
    public function select_payable_accepts_mixed_eloquent_model_and_stdclass_rows(): void
    {
        $modelRow = new ScholarshipWithholding([
            'period_year'  => 2026,
            'period_month' => 7, // offset 1
        ]);
        $modelRow->id = 1;

        $stdClassRow              = new \stdClass();
        $stdClassRow->id          = 2;
        $stdClassRow->period_year = 2026;
        $stdClassRow->period_month = 6; // offset 2

        $result = PayableWithholdingWindow::selectPayable([$modelRow, $stdClassRow], 2026, 8);

        $this->assertSame([1, 2], $result->pluck('id')->all());
    }

    // ---- payableIdsForUser (DB-touching) --------------------------------------

    private function makeUser(): User
    {
        return User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
    }

    private function makeWithholding(User $user, int $periodYear, int $periodMonth, float $withheld = 500.00): ScholarshipWithholding
    {
        $refrend = ScholarshipRefrend::create([
            'user_id'                      => $user->id,
            'period_year'                  => $periodYear,
            'period_month'                 => $periodMonth,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'WITHHELD',
            'workflow_status'              => 'CLOSED',
            'base_amount'                  => $withheld,
            'discount_percentage'          => 0,
            'discount_amount'              => $withheld,
            'final_amount'                 => 0,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);

        return ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $refrend->id,
            'period_year'       => $periodYear,
            'period_month'      => $periodMonth,
            'withheld_amount'   => number_format($withheld, 2, '.', ''),
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);
    }

    /** @test */
    public function payable_ids_for_user_returns_only_in_window_top_two_ids(): void
    {
        $user = $this->makeUser();

        $inWindowRecent = $this->makeWithholding($user, 2026, 7); // offset 1
        $inWindowOlder  = $this->makeWithholding($user, 2026, 6); // offset 2
        $inWindowOldest = $this->makeWithholding($user, 2026, 5); // offset 3, rank 3rd -> excluded
        $outOfWindow    = $this->makeWithholding($user, 2026, 4); // offset 4 -> excluded

        $ids = PayableWithholdingWindow::payableIdsForUser($user->id, 2026, 8);

        $this->assertSame([$inWindowRecent->id, $inWindowOlder->id], $ids);
        $this->assertNotContains($inWindowOldest->id, $ids);
        $this->assertNotContains($outOfWindow->id, $ids);
    }

    /** @test */
    public function payable_ids_for_user_ignores_already_paid_rows(): void
    {
        $user = $this->makeUser();

        $pending = $this->makeWithholding($user, 2026, 7);
        $paid    = $this->makeWithholding($user, 2026, 6);
        $paid->update(['status' => 'PAID', 'paid_amount' => $paid->withheld_amount]);

        $ids = PayableWithholdingWindow::payableIdsForUser($user->id, 2026, 8);

        $this->assertSame([$pending->id], $ids);
    }

    /** @test */
    public function payable_ids_for_user_returns_empty_array_when_nothing_is_pending(): void
    {
        $user = $this->makeUser();

        $ids = PayableWithholdingWindow::payableIdsForUser($user->id, 2026, 8);

        $this->assertSame([], $ids);
    }
}
