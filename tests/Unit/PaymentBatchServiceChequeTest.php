<?php

namespace Tests\Unit;

use App\Enums\PaymentMethod;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendManualPayment;
use App\Models\User;
use App\Services\Scholarship\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/pago-cheque PR2 (spec R2.1-R2.3, R2.6, R5): effective payment method
 * per row, spd-existence based has-payment-data, partition and cheque summary.
 */
class PaymentBatchServiceChequeTest extends TestCase
{
    use RefreshDatabase;

    private PaymentBatchService $service;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PaymentBatchService::class);
    }

    private function makeRefrend(PaymentMethod|null $spdMethod, array $refrendOverrides = [], string $name = 'Becario'): ScholarshipRefrend
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => self::CAMPUS, 'active' => true]);

        if ($spdMethod === PaymentMethod::CHEQUE) {
            ScholarshipPaymentData::factory()->cheque('Madre')->create(['user_id' => $user->id]);
        } elseif ($spdMethod === PaymentMethod::TRANSFER) {
            ScholarshipPaymentData::factory()->create(['user_id' => $user->id, 'account_number' => '0123456789']);
        }

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => $name,
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $refrendOverrides));
    }

    private function rowFor(ScholarshipRefrend $refrend): array
    {
        $rows = $this->service->rows(self::CAMPUS, self::YEAR, self::MONTH);

        return collect($rows)->firstWhere('refrend_id', $refrend->id);
    }

    /** @test */
    public function cheque_row_without_bank_data_is_payable_and_carries_cheque_fields(): void
    {
        $row = $this->rowFor($this->makeRefrend(PaymentMethod::CHEQUE));

        $this->assertTrue($row['is_payable']);
        $this->assertSame('CHEQUE', $row['payment_method']);
        $this->assertSame('Madre', $row['cheque_beneficiary']);
        $this->assertSame([], $row['manual_payments']);
        foreach (['cheque_number', 'cheque_delivered_at', 'cheque_notes', 'transfer_reference', 'transfer_paid_at', 'transfer_notes'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $row);
        }
    }

    /** @test */
    public function transfer_row_defaults_and_still_validates_bank_data(): void
    {
        $row = $this->rowFor($this->makeRefrend(PaymentMethod::TRANSFER));

        $this->assertSame('TRANSFER', $row['payment_method']);
        $this->assertTrue($row['is_payable']);
    }

    /** @test */
    public function row_without_payment_data_is_transfer_and_blocked_as_missing_payment_data(): void
    {
        $row = $this->rowFor($this->makeRefrend(null));

        $this->assertSame('TRANSFER', $row['payment_method']);
        $this->assertContains('MISSING_PAYMENT_DATA', array_column($row['blocking_reasons'], 'code'));
    }

    /** @test */
    public function row_in_a_payment_batch_is_always_transfer_even_if_spd_is_cheque(): void
    {
        $batch   = ScholarshipPaymentBatch::create($this->batchAttributes());
        $refrend = $this->makeRefrend(PaymentMethod::CHEQUE, ['payment_batch_id' => $batch->id, 'status' => 'PAID']);

        $this->assertSame('TRANSFER', $this->rowFor($refrend)['payment_method']);
    }

    /** @test */
    public function paid_snapshot_wins_over_the_current_spd_method(): void
    {
        $refrend = $this->makeRefrend(PaymentMethod::TRANSFER, [
            'status'              => 'PAID',
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now(),
            'paid_payment_method' => PaymentMethod::CHEQUE->value,
        ]);
        $this->freeze($refrend, 'beca', 'A123', '800.00', 'Entregado en sede');

        $row = $this->rowFor($refrend);

        $this->assertSame('CHEQUE', $row['payment_method']);
        $this->assertSame([[
            'concept' => 'beca', 'method' => 'CHEQUE', 'reference' => 'A123',
            'amount' => '800.00', 'paid_at' => '2026-05-10', 'notes' => 'Entregado en sede',
        ]], $row['manual_payments']);
    }

    /** @test */
    public function manual_payments_come_beca_first_with_frozen_amounts_in_one_batched_query(): void
    {
        $makePaid = function (string $name) {
            $refrend = $this->makeRefrend(PaymentMethod::CHEQUE, [
                'status' => 'PAID', 'workflow_status' => 'CLOSED', 'locked_at' => now(),
                'paid_payment_method' => PaymentMethod::CHEQUE->value,
            ], $name);
            // Insert apoyo first: the payload order must not depend on insertion order.
            $this->freeze($refrend, 'apoyo', $name . '-AP', '200.00');
            $this->freeze($refrend, 'beca', $name . '-BE', '800.00');

            return $refrend;
        };
        $first = $makePaid('One');

        DB::enableQueryLog();
        $this->service->rows(self::CAMPUS, self::YEAR, self::MONTH);
        $queriesForOne = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (['Two', 'Three', 'Four'] as $name) {
            $makePaid($name);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->service->rows(self::CAMPUS, self::YEAR, self::MONTH);
        $queriesForFour = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesForOne, $queriesForFour);
        $this->assertSame(
            ['beca', 'apoyo'],
            array_column(collect($rows)->firstWhere('refrend_id', $first->id)['manual_payments'], 'concept')
        );
    }

    /** @test */
    public function legacy_paid_row_without_snapshot_is_transfer_even_if_spd_is_now_cheque(): void
    {
        $refrend = $this->makeRefrend(PaymentMethod::CHEQUE, [
            'status'          => 'PAID',
            'workflow_status' => 'CLOSED',
            'locked_at'       => now(),
        ]);

        $rows = $this->service->rows(self::CAMPUS, self::YEAR, self::MONTH);
        [$transfer, $cheque] = $this->service->partitionByMethod($rows);

        $this->assertSame('TRANSFER', $this->rowFor($refrend)['payment_method']);
        $this->assertContains($refrend->id, array_column($transfer, 'refrend_id'));
        $this->assertNotContains($refrend->id, array_column($cheque, 'refrend_id'));
    }

    /** @test */
    public function pending_refrend_follows_the_current_spd_method(): void
    {
        $refrend = $this->makeRefrend(PaymentMethod::TRANSFER);
        $this->assertSame('TRANSFER', $this->rowFor($refrend)['payment_method']);

        ScholarshipPaymentData::where('user_id', $refrend->user_id)->update([
            'payment_method' => PaymentMethod::CHEQUE->value,
            'bank_name'      => null,
            'account_number' => null,
        ]);

        $this->assertSame('CHEQUE', $this->rowFor($refrend)['payment_method']);
    }

    /** @test */
    public function partition_by_method_splits_rows_preserving_order(): void
    {
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'A transfer');
        $this->makeRefrend(PaymentMethod::CHEQUE, [], 'B cheque');
        $this->makeRefrend(null, [], 'C no data');

        [$transfer, $cheque] = $this->service->partitionByMethod($this->service->rows(self::CAMPUS, self::YEAR, self::MONTH));

        $this->assertSame(['A transfer', 'C no data'], array_column($transfer, 'snapshot_name'));
        $this->assertSame(['B cheque'], array_column($cheque, 'snapshot_name'));
    }

    /** @test */
    public function cheque_summary_counts_ready_paid_and_blocking(): void
    {
        $this->makeRefrend(PaymentMethod::CHEQUE, [], 'Ready');
        $this->makeRefrend(PaymentMethod::CHEQUE, ['final_amount' => 500.00], 'Ready 2');
        $this->makeRefrend(PaymentMethod::CHEQUE, ['workflow_status' => 'EN_REVISION'], 'Blocked');
        $this->makeRefrend(PaymentMethod::CHEQUE, [
            'status' => 'PAID', 'workflow_status' => 'CLOSED', 'locked_at' => now(),
            'paid_payment_method' => PaymentMethod::CHEQUE->value, 'final_amount' => 250.00,
        ], 'Paid');
        $paid = ScholarshipRefrend::where('snapshot_name', 'Paid')->first();
        $this->freeze($paid, 'beca', 'P-BE', '150.00');
        $this->freeze($paid, 'apoyo', 'P-AP', '100.00');

        [, $cheque] = $this->service->partitionByMethod($this->service->rows(self::CAMPUS, self::YEAR, self::MONTH));
        $summary    = $this->service->chequeSummary($cheque);

        $this->assertSame([
            'total'         => 4,
            'ready'         => 2,
            'paid'          => 1,
            'blocking'      => 1,
            'ready_amount'  => '1500.00',
            'paid_amount'   => '250.00',
            // sdd/archivo-pago-beca-apoyo PR2: per-concept sums (no apoyo in this fixture).
            'ready_beca_amount'  => '1500.00',
            'ready_apoyo_amount' => '0.00',
            // Paid amounts are the FROZEN child rows (150 + 100), not the derived split.
            'paid_beca_amount'   => '150.00',
            'paid_apoyo_amount'  => '100.00',
        ], $summary);
    }

    /** @test */
    public function paid_rows_excludes_cheque_rows_even_if_linked_to_the_batch(): void
    {
        $batch    = ScholarshipPaymentBatch::create($this->batchAttributes());
        $transfer = $this->makeRefrend(PaymentMethod::TRANSFER, ['payment_batch_id' => $batch->id, 'status' => 'PAID'], 'T');
        $this->makeRefrend(PaymentMethod::CHEQUE, [
            'payment_batch_id' => $batch->id, 'status' => 'PAID', 'paid_payment_method' => PaymentMethod::CHEQUE->value,
        ], 'C');

        $rows = $this->service->paidRows($batch);

        $this->assertSame([$transfer->id], array_column($rows, 'refrend_id'));
    }

    private function freeze(ScholarshipRefrend $refrend, string $concept, string $reference, string $amount, ?string $notes = null): void
    {
        ScholarshipRefrendManualPayment::factory()->create([
            'refrend_id' => $refrend->id, 'concept' => $concept, 'method' => 'CHEQUE',
            'reference' => $reference, 'amount' => $amount, 'paid_at' => '2026-05-10', 'notes' => $notes,
        ]);
    }

    private function batchAttributes(): array
    {
        return [
            'generation_id'   => 1,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => User::factory()->create(['user_type' => 'ADMIN'])->id,
            'processed_at'    => now(),
        ];
    }
}
