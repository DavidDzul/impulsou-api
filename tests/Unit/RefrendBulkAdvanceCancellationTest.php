<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\RefrendBulkQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S7 (spec R7 / S13c, R18 / S37): the bulk
 * master table excludes CANCELLED advance months from `advance_paid` and
 * exposes `advance_overpayment` on the cycle-cut row.
 */
class RefrendBulkAdvanceCancellationTest extends TestCase
{
    use RefreshDatabase;

    private RefrendBulkQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(RefrendBulkQueryService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function makeRefrend(int $userId, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
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
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function makeUser(): User
    {
        return User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
    }

    /**
     * Origin March, advance months April+May. Returns the pieces so each
     * test can cancel what it needs.
     *
     * @param array<string, mixed> $headerOverrides
     * @return array{user: User, origin: ScholarshipRefrend, header: ScholarshipAdvancePayment, months: array<int, ScholarshipAdvancePaymentMonth>, futures: array<int, ScholarshipRefrend>}
     */
    private function makeAdvance(array $headerOverrides = [], int $originMonth = 3, string $monthStatus = 'PENDING'): array
    {
        $user   = $this->makeUser();
        $origin = $this->makeRefrend($user->id, $originMonth);

        $header = ScholarshipAdvancePayment::create(array_merge([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => $originMonth,
            'months_count'        => 2,
            'total_amount'        => 5000.00,
            'status'              => 'ACTIVE',
        ], $headerOverrides));

        $months  = [];
        $futures = [];
        foreach ([4, 5] as $month) {
            $future    = $this->makeRefrend($user->id, $month);
            $futures[] = $future;
            $months[]  = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id,
                'user_id'            => $user->id,
                'period_year'        => 2026,
                'period_month'       => $month,
                'amount'             => 2500.00,
                'refrend_id'         => $future->id,
                'status'             => $monthStatus,
            ]);
        }

        return compact('user', 'origin', 'header', 'months', 'futures');
    }

    private function rowsById(int $month): array
    {
        $result = $this->service->buildTable(2026, $month, null, null, 1, 200);

        $byId = [];
        foreach ($result['rows'] as $row) {
            $byId[$row['refrend']['id']] = $row;
        }

        return $byId;
    }

    // ── S13c: advance_paid excludes CANCELLED months ─────────────────────

    /** @test */
    public function a_row_whose_only_advance_month_is_cancelled_is_not_advance_paid(): void
    {
        $f = $this->makeAdvance();
        $f['months'][1]->update(['status' => 'CANCELLED']);

        $row = $this->rowsById(5)[$f['futures'][1]->id];

        $this->assertFalse($row['advance_paid']);
        $this->assertNull($row['advance_paid_amount']);
        $this->assertNull($row['advance_paid_origin_year']);
        $this->assertNull($row['advance_paid_origin_month']);
    }

    /** @test */
    public function an_active_advance_row_keeps_rendering_as_advance_paid(): void
    {
        $f   = $this->makeAdvance();
        $row = $this->rowsById(4)[$f['futures'][0]->id];

        $this->assertTrue($row['advance_paid']);
        $this->assertSame('2500.00', $row['advance_paid_amount']);
        $this->assertSame(2026, $row['advance_paid_origin_year']);
        $this->assertSame(3, $row['advance_paid_origin_month']);
        $this->assertNull($row['advance_overpayment']);
    }

    /** @test */
    public function a_partially_cancelled_header_only_hides_the_cancelled_months_from_advance_paid(): void
    {
        $f = $this->makeAdvance(['status' => 'PARTIALLY_CANCELLED']);
        $f['months'][1]->update(['status' => 'CANCELLED']);

        $this->assertTrue($this->rowsById(4)[$f['futures'][0]->id]['advance_paid']);
        $this->assertFalse($this->rowsById(5)[$f['futures'][1]->id]['advance_paid']);
    }

    // ── S37: advance_overpayment on the cut row ──────────────────────────

    /** @test */
    public function the_cut_row_exposes_the_overpayment_with_cancelled_months_and_origin(): void
    {
        $f = $this->makeAdvance(['status' => 'CANCELLED']);
        $f['months'][0]->update(['status' => 'CANCELLED']);
        $f['months'][1]->update(['status' => 'CANCELLED']);
        $f['header']->update([
            'cancelled_cut_refrend_id' => $f['futures'][0]->id,
            'cancelled_amount'         => 5000.00,
            'overpaid_amount'          => 5000.00,
        ]);

        $row = $this->rowsById(4)[$f['futures'][0]->id];

        $this->assertSame([
            'amount' => '5000.00',
            'months' => [
                ['period_year' => 2026, 'period_month' => 4, 'amount' => '2500.00'],
                ['period_year' => 2026, 'period_month' => 5, 'amount' => '2500.00'],
            ],
            'origin_period_year'  => 2026,
            'origin_period_month' => 3,
        ], $row['advance_overpayment']);
    }

    /** @test */
    public function rows_other_than_the_cut_row_have_no_overpayment(): void
    {
        $f = $this->makeAdvance(['status' => 'CANCELLED']);
        $f['months'][0]->update(['status' => 'CANCELLED']);
        $f['months'][1]->update(['status' => 'CANCELLED']);
        $f['header']->update([
            'cancelled_cut_refrend_id' => $f['futures'][0]->id,
            'cancelled_amount'         => 5000.00,
            'overpaid_amount'          => 5000.00,
        ]);

        $this->assertNull($this->rowsById(5)[$f['futures'][1]->id]['advance_overpayment']);
        $this->assertNull($this->rowsById(3)[$f['origin']->id]['advance_overpayment']);
    }

    /** @test */
    public function an_unpaid_origin_cut_has_no_overpayment_row_flag(): void
    {
        $f = $this->makeAdvance(['status' => 'CANCELLED']);
        $f['months'][0]->update(['status' => 'CANCELLED']);
        $f['months'][1]->update(['status' => 'CANCELLED']);
        $f['header']->update([
            'cancelled_cut_refrend_id' => $f['futures'][0]->id,
            'cancelled_amount'         => 5000.00,
            'overpaid_amount'          => 0,
        ]);

        $this->assertNull($this->rowsById(4)[$f['futures'][0]->id]['advance_overpayment']);
    }

    /** @test */
    public function a_partial_cut_lists_only_the_cancelled_months(): void
    {
        $f = $this->makeAdvance(['status' => 'PARTIALLY_CANCELLED']);
        $f['months'][1]->update(['status' => 'CANCELLED']);
        $f['header']->update([
            'cancelled_cut_refrend_id' => $f['futures'][1]->id,
            'cancelled_amount'         => 2500.00,
            'overpaid_amount'          => 2500.00,
        ]);

        $row = $this->rowsById(5)[$f['futures'][1]->id];

        $this->assertSame('2500.00', $row['advance_overpayment']['amount']);
        $this->assertSame(
            [['period_year' => 2026, 'period_month' => 5, 'amount' => '2500.00']],
            $row['advance_overpayment']['months']
        );
    }

    /** @test */
    public function two_overpaid_headers_on_the_same_cut_row_are_summed_with_the_earliest_origin(): void
    {
        $f = $this->makeAdvance(['status' => 'CANCELLED'], 3);
        $f['months'][0]->update(['status' => 'CANCELLED']);
        $f['months'][1]->update(['status' => 'CANCELLED']);

        // Second, later origin (February) advancing June.
        $origin2 = $this->makeRefrend($f['user']->id, 2);
        $header2 = ScholarshipAdvancePayment::create([
            'user_id'                  => $f['user']->id,
            'origin_refrend_id'        => $origin2->id,
            'origin_period_year'       => 2026,
            'origin_period_month'      => 2,
            'months_count'             => 1,
            'total_amount'             => 1000.00,
            'status'                   => 'CANCELLED',
            'cancelled_cut_refrend_id' => $f['futures'][0]->id,
            'cancelled_amount'         => 1000.00,
            'overpaid_amount'          => 1000.00,
        ]);
        $june = $this->makeRefrend($f['user']->id, 6);
        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header2->id,
            'user_id'            => $f['user']->id,
            'period_year'        => 2026,
            'period_month'       => 6,
            'amount'             => 1000.00,
            'refrend_id'         => $june->id,
            'status'             => 'CANCELLED',
        ]);
        $f['header']->update([
            'cancelled_cut_refrend_id' => $f['futures'][0]->id,
            'cancelled_amount'         => 5000.00,
            'overpaid_amount'          => 5000.00,
        ]);

        $overpayment = $this->rowsById(4)[$f['futures'][0]->id]['advance_overpayment'];

        $this->assertSame('6000.00', $overpayment['amount']);
        $this->assertSame(2, $overpayment['origin_period_month']);
        $this->assertSame([4, 5, 6], array_column($overpayment['months'], 'period_month'));
    }

    // ── No N+1: constant extra queries ───────────────────────────────────

    /** @test */
    public function the_overpayment_lookup_uses_a_constant_number_of_queries(): void
    {
        $one = $this->makeAdvance(['status' => 'CANCELLED']);
        $one['header']->update([
            'cancelled_cut_refrend_id' => $one['futures'][0]->id,
            'overpaid_amount'          => 5000.00,
        ]);
        $one['months'][0]->update(['status' => 'CANCELLED']);

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->service->buildTable(2026, 4, null, null, 1, 200);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $before = $countQueries();

        for ($i = 0; $i < 3; $i++) {
            $extra = $this->makeAdvance(['status' => 'CANCELLED']);
            $extra['header']->update([
                'cancelled_cut_refrend_id' => $extra['futures'][0]->id,
                'overpaid_amount'          => 5000.00,
            ]);
            $extra['months'][0]->update(['status' => 'CANCELLED']);
        }

        $this->assertSame($before, $countQueries());
    }
}
