<?php

namespace Tests\Unit;

use App\Enums\PaymentMethod;
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
 * sdd/descuento-beca-apoyo PR2: rows() carries a `net_breakdown` per row
 * (RefrendNetComponents over the DISPLAYED, floored total) and summary() /
 * generationSummary() expose net_* keys that are sums of per-row cents.
 * Money fields the bank file and the manual sections depend on must stay put.
 */
class PaymentBatchServiceNetBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    private const NET_KEYS = [
        'beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage',
        'pending_and_advance',
    ];

    private const BASELINE_QUERIES = 5;

    private PaymentBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PaymentBatchService::class);
    }

    private function makeRefrend(array $overrides = [], string $channel = 'bbva'): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => self::CAMPUS,
            'active'    => true,
        ]);

        if ($channel === 'cheque') {
            ScholarshipPaymentData::factory()->cheque('Madre')->create(['user_id' => $user->id]);
        } else {
            $code = $channel === 'other_bank' ? '072' : '012';
            ScholarshipPaymentData::factory()->create([
                'user_id'        => $user->id,
                'bank_id'        => Bank::where('code', $code)->value('id'),
                'bank_name'      => $channel === 'other_bank' ? 'Banorte' : 'BBVA',
                'account_number' => '0123456789',
            ]);
        }

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'LISTO_PARA_PAGO',
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

    private function rows(): array
    {
        return $this->service->rows(self::CAMPUS, self::YEAR, self::MONTH);
    }

    private function rowFor(ScholarshipRefrend $refrend): array
    {
        foreach ($this->rows() as $row) {
            if ($row['refrend_id'] === $refrend->id) {
                return $row;
            }
        }
        $this->fail('Row not found');
    }

    private function cents(string $money): int
    {
        return (int) round((float) $money * 100);
    }

    private function netSum(array $net): int
    {
        $sum = 0;
        foreach (self::NET_KEYS as $key) {
            $sum += $this->cents($net[$key]);
        }

        return $sum;
    }

    /** @test */
    public function rows_expose_the_net_split_for_the_golden_case(): void
    {
        $row = $this->rowFor($this->makeRefrend());

        $net = $row['net_breakdown'];
        $this->assertSame('1545.45', $net['beca']);
        $this->assertSame('154.55', $net['apoyo']);
        $this->assertSame('1700.00', $net['total']);
        $this->assertSame('1700.00', $row['total_to_pay']);
        $this->assertSame('2000.00', $net['nominal']['beca']);
        $this->assertSame('200.00', $net['nominal']['apoyo']);
        $this->assertSame($this->cents($row['total_to_pay']), $this->netSum($net));
    }

    /** @test */
    public function rows_keep_pending_and_advance_out_of_the_split(): void
    {
        $row = $this->rowFor($this->makeRefrend([
            'amount_pending_from_previous' => 300.00,
            'advance_payment_amount'       => 100.00,
        ]));

        $net = $row['net_breakdown'];
        $this->assertSame('400.00', $net['pending_and_advance']);
        $this->assertSame('1545.45', $net['beca']);
        $this->assertSame('154.55', $net['apoyo']);
        $this->assertSame('2100.00', $row['total_to_pay']);
        $this->assertSame($this->cents($row['total_to_pay']), $this->netSum($net));
    }

    /** @test */
    public function rows_pass_the_floored_total_so_the_cent_lands_in_the_month_split(): void
    {
        $row = $this->rowFor($this->makeRefrend([
            'discount_amount' => 2200.00,
            'final_amount'    => 0.00,
        ]));

        $this->assertSame('0.01', $row['total_to_pay']);
        $this->assertSame('0.01', $row['net_breakdown']['total']);
        $this->assertSame('0.00', $row['net_breakdown']['pending_and_advance']);
        $this->assertSame(1, $this->netSum($row['net_breakdown']));
    }

    /** @test */
    public function cheque_and_manual_transfer_rows_carry_the_net_breakdown_and_unchanged_totals(): void
    {
        $this->makeRefrend([], 'bbva');
        $this->makeRefrend([], 'cheque');
        $this->makeRefrend(['amount_pending_from_previous' => 50.00], 'other_bank');

        [$transfer, $cheque, $manual] = $this->service->partitionByMethod($this->rows());

        $this->assertCount(1, $transfer);
        $this->assertCount(1, $cheque);
        $this->assertCount(1, $manual);
        $this->assertSame('1700.00', $cheque[0]['total_to_pay']);
        $this->assertSame('1545.45', $cheque[0]['net_breakdown']['beca']);
        $this->assertSame('1750.00', $manual[0]['total_to_pay']);
        $this->assertSame('50.00', $manual[0]['net_breakdown']['pending_and_advance']);
        foreach ([$transfer[0], $cheque[0], $manual[0]] as $row) {
            $this->assertSame($this->cents($row['total_to_pay']), $this->netSum($row['net_breakdown']));
        }
    }

    /** @test */
    public function summary_net_keys_are_the_sum_of_ready_rows_and_add_up_to_total_amount(): void
    {
        $this->makeRefrend(['snapshot_name' => 'A']);
        $this->makeRefrend(['snapshot_name' => 'B', 'amount_pending_from_previous' => 120.00]);
        $this->makeRefrend([
            'snapshot_name'                      => 'C',
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX_IU->value,
            'base_amount'                        => 800.00,
            'snapshot_gross_amount'              => 1100.00,
            'snapshot_monto_apoyo'               => null,
            'snapshot_temporary_increase_amount' => 300.00,
            'final_amount'                       => 1100.00,
            'discount_amount'                    => 0,
        ]);
        $this->makeRefrend(['snapshot_name' => 'Blocked'], 'bbva');
        // Not ready: no enrollment is the default for factories, so mark one explicitly blocked.
        ScholarshipRefrend::where('snapshot_name', 'Blocked')->update(['workflow_status' => 'EN_REVISION']);

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $expected = array_fill_keys(self::NET_KEYS, 0);
        foreach ($rows as $row) {
            if (! $row['is_payable']) {
                continue;
            }
            foreach (self::NET_KEYS as $key) {
                $expected[$key] += $this->cents($row['net_breakdown'][$key]);
            }
        }

        $this->assertSame($this->money($expected['beca']), $summary['net_beca_amount']);
        $this->assertSame($this->money($expected['apoyo']), $summary['net_apoyo_amount']);
        $this->assertSame($this->money($expected['pago_iu']), $summary['net_pago_iu_amount']);
        $this->assertSame($this->money($expected['temporary_increase']), $summary['net_temporary_increase_amount']);
        $this->assertSame($this->money($expected['telmex_coverage']), $summary['net_telmex_coverage_amount']);
        $this->assertSame($this->money($expected['pending_and_advance']), $summary['pending_and_advance_amount']);
        $this->assertSame('120.00', $summary['pending_and_advance_amount']);

        $netTotal = $this->cents($summary['net_beca_amount']) + $this->cents($summary['net_apoyo_amount'])
            + $this->cents($summary['net_pago_iu_amount']) + $this->cents($summary['net_temporary_increase_amount'])
            + $this->cents($summary['net_telmex_coverage_amount']) + $this->cents($summary['pending_and_advance_amount']);
        $this->assertSame($this->cents($summary['total_amount']), $netTotal);
    }

    /** @test */
    public function summary_keeps_the_existing_nominal_keys_untouched(): void
    {
        $this->makeRefrend();
        $summary = $this->service->summary($this->rows());

        $this->assertSame('2000.00', $summary['beca_amount']);
        $this->assertSame('200.00', $summary['apoyo_amount']);
        $this->assertSame('0.00', $summary['pago_iu_amount']);
        $this->assertSame('1700.00', $summary['total_amount']);
        $this->assertSame('500.00', $summary['difference_amount']);
    }

    /** @test */
    public function generation_summary_net_keys_sum_paid_and_pending_rows_and_match_total_amount(): void
    {
        $this->makeRefrend(['snapshot_name' => 'A']);
        $this->makeRefrend(['snapshot_name' => 'B', 'advance_payment_amount' => 90.00]);
        $batch = ScholarshipPaymentBatch::create($this->batchAttributes());
        $this->makeRefrend(['snapshot_name' => 'Paid', 'status' => 'PAID', 'payment_batch_id' => $batch->id]);

        $rows    = $this->rows();
        $summary = $this->service->generationSummary($rows);

        $this->assertSame('90.00', $summary['pending_and_advance_amount']);
        $this->assertArrayHasKey('net_beca_amount', $summary);

        $netTotal = $this->cents($summary['net_beca_amount']) + $this->cents($summary['net_apoyo_amount'])
            + $this->cents($summary['net_pago_iu_amount']) + $this->cents($summary['net_temporary_increase_amount'])
            + $this->cents($summary['net_telmex_coverage_amount']) + $this->cents($summary['pending_and_advance_amount']);
        $this->assertSame($this->cents($summary['total_amount']), $netTotal);
        $this->assertSame('3490.00', $summary['pending_amount']);
        $this->assertSame('1700.00', $summary['paid_amount']);
    }

    /** @test */
    public function rows_query_count_is_unchanged_by_the_net_breakdown(): void
    {
        $this->makeRefrend(['snapshot_name' => 'A']);
        $this->makeRefrend(['snapshot_name' => 'B']);
        $this->makeRefrend(['snapshot_name' => 'C'], 'cheque');

        DB::enableQueryLog();
        $this->rows();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Baseline measured before the change: constant per batch, no per-row queries.
        $this->assertSame(self::BASELINE_QUERIES, $count);
    }

    /** @test */
    public function paid_rows_for_the_bank_file_do_not_gain_the_net_breakdown(): void
    {
        $batch = ScholarshipPaymentBatch::create($this->batchAttributes());
        $this->makeRefrend(['status' => 'PAID', 'payment_batch_id' => $batch->id, 'amount_pending_from_previous' => 40.00]);

        $paid = $this->service->paidRows($batch);

        $this->assertCount(1, $paid);
        $this->assertArrayNotHasKey('net_breakdown', $paid[0]);
        $this->assertSame('1740.00', $paid[0]['total_to_pay']);
    }

    private function batchAttributes(): array
    {
        return [
            'generation_id'   => 1,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 1,
            'total_amount'    => '1700.00',
            'processed_by_id' => User::factory()->create(['user_type' => 'ADMIN'])->id,
            'processed_at'    => now(),
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
