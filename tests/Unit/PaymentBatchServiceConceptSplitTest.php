<?php

namespace Tests\Unit;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo PR2 (task 2.7): rows() carries `concept_split`
 * per row and the cheque / other-bank summaries add Beca / Apoyo sums. Display
 * only: every pre-existing amount and the one-payment-per-row semantics stay.
 */
class PaymentBatchServiceConceptSplitTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    private PaymentBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PaymentBatchService::class);
    }

    private function makeRefrend(array $overrides = [], string $channel = 'bbva'): ScholarshipRefrend
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => self::CAMPUS, 'active' => true]);

        if ($channel === 'cheque') {
            ScholarshipPaymentData::factory()->cheque('Madre')->create(['user_id' => $user->id]);
        } else {
            $code = $channel === 'other_bank' ? '072' : '012';
            ScholarshipPaymentData::factory()->create([
                'user_id'        => $user->id,
                'bank_id'        => Bank::where('code', $code)->value('id'),
                'bank_name'      => $channel === 'other_bank' ? 'Banorte' : 'BBVA',
                'account_number' => $channel === 'other_bank' ? '072000000000000009' : '0123456789',
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

    private function cents(string $money): int
    {
        return (int) round((float) $money * 100);
    }

    /** @test */
    public function rows_expose_the_concept_split_for_the_golden_case(): void
    {
        $this->makeRefrend();

        $row = $this->rows()[0];

        $this->assertSame(['beca' => '1545.45', 'apoyo' => '154.55'], $row['concept_split']);
        $this->assertSame('1700.00', $row['total_to_pay']);
    }

    /** @test */
    public function the_concept_split_always_adds_up_to_the_displayed_total(): void
    {
        $this->makeRefrend(['snapshot_name' => 'A']);
        $this->makeRefrend(['snapshot_name' => 'B', 'amount_pending_from_previous' => 123.45, 'advance_payment_amount' => 50.55]);
        $this->makeRefrend(['snapshot_name' => 'C', 'snapshot_monto_apoyo' => null, 'final_amount' => 999.99]);
        $this->makeRefrend(['snapshot_name' => 'D', 'discount_amount' => 2200.00, 'final_amount' => 0.00]);

        $rows = $this->rows();

        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                $this->cents($row['total_to_pay']),
                $this->cents($row['concept_split']['beca']) + $this->cents($row['concept_split']['apoyo']),
                $row['snapshot_name']
            );
        }
        $floored = collect($rows)->firstWhere('snapshot_name', 'D');
        $this->assertSame(['beca' => '0.01', 'apoyo' => '0.00'], $floored['concept_split']);
    }

    /** @test */
    public function cheque_and_other_bank_rows_carry_the_split_and_stay_one_payment_per_row(): void
    {
        $this->makeRefrend(['snapshot_name' => 'T'], 'bbva');
        $this->makeRefrend(['snapshot_name' => 'C'], 'cheque');
        $this->makeRefrend(['snapshot_name' => 'M', 'snapshot_monto_apoyo' => null, 'final_amount' => 800.00], 'other_bank');

        [$transfer, $cheque, $manual] = $this->service->partitionByMethod($this->rows());

        $this->assertCount(1, $transfer);
        $this->assertCount(1, $cheque);
        $this->assertCount(1, $manual);
        $this->assertSame(['beca' => '1545.45', 'apoyo' => '154.55'], $cheque[0]['concept_split']);
        $this->assertSame('1700.00', $cheque[0]['total_to_pay']);
        $this->assertSame(['beca' => '800.00', 'apoyo' => '0.00'], $manual[0]['concept_split']);
    }

    /** @test */
    public function manual_summaries_add_ready_and_paid_sums_per_concept(): void
    {
        $this->makeRefrend(['snapshot_name' => 'Ready cheque'], 'cheque');
        $this->makeRefrend(['snapshot_name' => 'Paid cheque', 'status' => 'PAID', 'paid_payment_method' => 'CHEQUE'], 'cheque');
        $this->makeRefrend(['snapshot_name' => 'Ready other', 'snapshot_monto_apoyo' => null, 'final_amount' => 800.00], 'other_bank');

        [, $cheque, $manual] = $this->service->partitionByMethod($this->rows());
        $chequeSummary       = $this->service->chequeSummary($cheque);
        $manualSummary       = $this->service->manualTransferSummary($manual);

        $this->assertSame('1700.00', $chequeSummary['ready_amount']);
        $this->assertSame('1545.45', $chequeSummary['ready_beca_amount']);
        $this->assertSame('154.55', $chequeSummary['ready_apoyo_amount']);
        $this->assertSame('1700.00', $chequeSummary['paid_amount']);
        $this->assertSame('1545.45', $chequeSummary['paid_beca_amount']);
        $this->assertSame('154.55', $chequeSummary['paid_apoyo_amount']);
        $this->assertSame('800.00', $manualSummary['ready_beca_amount']);
        $this->assertSame('0.00', $manualSummary['ready_apoyo_amount']);
        $this->assertSame('0.00', $manualSummary['paid_beca_amount']);
        $this->assertSame('0.00', $manualSummary['paid_apoyo_amount']);
        foreach (['ready', 'paid'] as $state) {
            $this->assertSame(
                $this->cents($chequeSummary["{$state}_amount"]),
                $this->cents($chequeSummary["{$state}_beca_amount"]) + $this->cents($chequeSummary["{$state}_apoyo_amount"])
            );
        }
    }

    /** @test */
    public function rows_cost_exactly_two_more_queries_whatever_the_batch_size(): void
    {
        $this->makeRefrend(['snapshot_name' => 'A']);
        DB::enableQueryLog();
        $this->rows();
        $one = count(DB::getQueryLog());

        foreach (['B', 'C', 'D', 'E'] as $name) {
            $this->makeRefrend(['snapshot_name' => $name, 'amount_pending_from_previous' => 10.00], $name === 'C' ? 'cheque' : 'bbva');
        }
        DB::flushQueryLog();
        $this->rows();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($one, $many);
        // 3 baseline queries (rows, incidents, advance-paid lookup) + 2 resolver queries.
        $this->assertSame(5, $many);
    }
}
