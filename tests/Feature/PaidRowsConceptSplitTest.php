<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Bank;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo PR2 (task 2.3): paidRows() carries
 * beca_amount / apoyo_amount whose sum is ALWAYS the (floored) total_to_pay,
 * at a constant query cost, and without touching the paid state.
 */
class PaidRowsConceptSplitTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    private PaymentBatchService $service;
    private ScholarshipPaymentBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PaymentBatchService::class);
        $this->batch   = ScholarshipPaymentBatch::create([
            'generation_id'   => 1,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 1,
            'total_amount'    => '1.00',
            'processed_by_id' => User::factory()->create(['user_type' => 'ADMIN'])->id,
            'processed_at'    => now(),
        ]);
    }

    private function paidRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => self::CAMPUS,
            'active'    => true,
        ]);
        ScholarshipPaymentData::factory()->create([
            'user_id'        => $user->id,
            'bank_id'        => Bank::where('code', '012')->value('id'),
            'bank_name'      => 'BBVA',
            'account_number' => '0123456789',
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::PAID->value,
            'workflow_status'              => 'CLOSED',
            'payment_batch_id'             => $this->batch->id,
            'locked_at'                    => now(),
            'base_amount'                  => 2000.00,
            'snapshot_monto_apoyo'         => 200.00,
            'snapshot_gross_amount'        => 2200.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 500.00,
            'final_amount'                 => 1700.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Becario ' . $user->id,
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function cents(string $money): int
    {
        return (int) round((float) $money * 100);
    }

    /** @test */
    public function golden_case_splits_the_total_into_beca_and_apoyo(): void
    {
        $this->paidRefrend();

        $row = $this->service->paidRows($this->batch)[0];

        $this->assertSame('1700.00', $row['total_to_pay']);
        $this->assertSame('1545.45', $row['beca_amount']);
        $this->assertSame('154.55', $row['apoyo_amount']);
    }

    /** @test */
    public function a_row_without_apoyo_is_all_beca(): void
    {
        $this->paidRefrend([
            'snapshot_monto_apoyo' => null,
            'snapshot_gross_amount' => 2000.00,
            'discount_amount' => 300.00,
            'final_amount' => 1700.00,
        ]);

        $row = $this->service->paidRows($this->batch)[0];

        $this->assertSame('1700.00', $row['beca_amount']);
        $this->assertSame('0.00', $row['apoyo_amount']);
    }

    /** @test */
    public function carryover_without_ledger_follows_the_row_month_weights(): void
    {
        $this->paidRefrend(['amount_pending_from_previous' => 300.00]);

        $row = $this->service->paidRows($this->batch)[0];

        $this->assertSame('2000.00', $row['total_to_pay']);
        $this->assertSame('1818.18', $row['beca_amount']);
        $this->assertSame('181.82', $row['apoyo_amount']);
    }

    /** @test */
    public function the_floor_cent_lands_in_beca(): void
    {
        $this->paidRefrend(['discount_amount' => 2200.00, 'final_amount' => 0.00]);

        $row = $this->service->paidRows($this->batch)[0];

        $this->assertSame('0.01', $row['total_to_pay']);
        $this->assertSame('0.01', $row['beca_amount']);
        $this->assertSame('0.00', $row['apoyo_amount']);
    }

    /** @test */
    public function beca_plus_apoyo_equals_total_to_pay_for_every_row_of_a_mixed_batch(): void
    {
        $this->paidRefrend(['snapshot_name' => 'A']);
        $this->paidRefrend(['snapshot_name' => 'B', 'amount_pending_from_previous' => 123.45, 'advance_payment_amount' => 50.55]);
        $this->paidRefrend(['snapshot_name' => 'C', 'snapshot_monto_apoyo' => null, 'final_amount' => 999.99]);
        $this->paidRefrend(['snapshot_name' => 'D', 'discount_amount' => 2200.00, 'final_amount' => 0.00]);
        $this->paidRefrend([
            'snapshot_name' => 'E',
            'snapshot_scholarship_type' => ScholarshipType::TELMEX->value,
            'snapshot_telmex_covered_amount' => 2000.00,
            'snapshot_monto_apoyo' => 200.00,
            'final_amount' => 77.77,
            'base_amount' => 2000.00,
        ]);

        $rows = $this->service->paidRows($this->batch);

        $this->assertCount(5, $rows);
        $combined = 0;
        $parts    = 0;
        foreach ($rows as $row) {
            $this->assertSame(
                $this->cents($row['total_to_pay']),
                $this->cents($row['beca_amount']) + $this->cents($row['apoyo_amount']),
                $row['snapshot_name']
            );
            $this->assertGreaterThanOrEqual(0, $this->cents($row['beca_amount']));
            $this->assertGreaterThanOrEqual(0, $this->cents($row['apoyo_amount']));
            $combined += $this->cents($row['total_to_pay']);
            $parts    += $this->cents($row['beca_amount']) + $this->cents($row['apoyo_amount']);
        }
        $this->assertSame($combined, $parts);
    }

    /** @test */
    public function paid_rows_query_count_does_not_grow_with_the_batch(): void
    {
        $this->paidRefrend(['snapshot_name' => 'A']);
        DB::enableQueryLog();
        $this->service->paidRows($this->batch);
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();

        foreach (['B', 'C', 'D', 'E', 'F'] as $name) {
            $this->paidRefrend(['snapshot_name' => $name, 'amount_pending_from_previous' => 10.00]);
        }
        DB::flushQueryLog();
        $this->service->paidRows($this->batch);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($one, $many);
        // 1 refrends query + exactly 2 for the carryover resolver.
        $this->assertSame(3, $many);
    }

    /** @test */
    public function paid_rows_still_hides_resolution_type_and_keeps_the_paid_state_untouched(): void
    {
        $refrend = $this->paidRefrend(['resolution_type' => 'RETENIDA', 'resolution_cause' => 'BAJO_PROMEDIO']);
        $keys    = ['status', 'workflow_status', 'locked_at', 'payment_batch_id', 'paid_payment_method'];
        $before  = $refrend->fresh()->only($keys);

        $row = $this->service->paidRows($this->batch)[0];

        $this->assertArrayNotHasKey('resolution_type', $row);
        $this->assertArrayNotHasKey('resolution_cause', $row);
        $this->assertEquals($before, $refrend->fresh()->only($keys));
    }
}
