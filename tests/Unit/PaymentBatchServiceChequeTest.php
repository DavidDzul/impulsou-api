<?php

namespace Tests\Unit;

use App\Enums\PaymentMethod;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertNull($row['cheque_number']);
        $this->assertNull($row['cheque_delivered_at']);
        $this->assertNull($row['cheque_notes']);
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
            'cheque_number'       => 'A123',
            'cheque_delivered_at' => '2026-05-10',
            'cheque_notes'        => 'Entregado en sede',
        ]);

        $row = $this->rowFor($refrend);

        $this->assertSame('CHEQUE', $row['payment_method']);
        $this->assertSame('A123', $row['cheque_number']);
        $this->assertSame('Entregado en sede', $row['cheque_notes']);
        $this->assertNotNull($row['cheque_delivered_at']);
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

        [, $cheque] = $this->service->partitionByMethod($this->service->rows(self::CAMPUS, self::YEAR, self::MONTH));
        $summary    = $this->service->chequeSummary($cheque);

        $this->assertSame([
            'total'         => 4,
            'ready'         => 2,
            'paid'          => 1,
            'blocking'      => 1,
            'ready_amount'  => '1500.00',
            'paid_amount'   => '250.00',
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
