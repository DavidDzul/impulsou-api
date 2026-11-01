<?php

namespace Tests\Unit;

use App\Enums\PaymentBatchType;
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
use Tests\TestCase;

/**
 * sdd/catalogo-bancos R5/R6: the payment channel of a row (BBVA batch,
 * other-bank manual transfer, cheque) derives from method + bank code, and
 * every becario sits in exactly one channel.
 */
class PaymentBatchServiceBankChannelTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;
    private const BANORTE_CLABE = '072000000000000009';

    private PaymentBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PaymentBatchService::class);
    }

    private function bankId(string $code): int
    {
        return (int) Bank::where('code', $code)->value('id');
    }

    /** @param array<string, mixed>|null $spd null = the becario has no payment data */
    private function makeRefrend(?array $spd, array $refrend = [], string $name = 'Becario'): ScholarshipRefrend
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => self::CAMPUS, 'active' => true]);

        if ($spd !== null) {
            ScholarshipPaymentData::factory()->create(array_merge(['user_id' => $user->id, 'account_number' => '0123456789'], $spd));
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
        ], $refrend));
    }

    private function rowFor(ScholarshipRefrend $refrend): array
    {
        return collect($this->service->rows(self::CAMPUS, self::YEAR, self::MONTH))->firstWhere('refrend_id', $refrend->id);
    }

    private function banorte(array $extra = []): array
    {
        return array_merge(['bank_id' => $this->bankId('072'), 'bank_name' => 'Banorte', 'account_number' => self::BANORTE_CLABE], $extra);
    }

    /** @test */
    public function bbva_transfer_row_stays_in_the_batch_channel(): void
    {
        $row = $this->rowFor($this->makeRefrend([]));

        $this->assertSame('TRANSFER', $row['payment_method']);
        $this->assertSame('012', $row['bank_code']);
        $this->assertTrue($row['is_payable']);
    }

    /** @test */
    public function other_bank_transfer_row_is_a_manual_transfer(): void
    {
        $row = $this->rowFor($this->makeRefrend($this->banorte()));

        $this->assertSame('MANUAL_TRANSFER', $row['payment_method']);
        $this->assertSame('072', $row['bank_code']);
        $this->assertTrue($row['is_payable']);
    }

    /** @test */
    public function legacy_10_digit_account_on_another_bank_is_blocked_with_invalid_clabe(): void
    {
        $row = $this->rowFor($this->makeRefrend($this->banorte(['account_number' => '0123456789'])));

        $this->assertSame('MANUAL_TRANSFER', $row['payment_method']);
        $this->assertFalse($row['is_payable']);
        $this->assertContains('INVALID_CLABE', array_column($row['blocking_reasons'], 'code'));
    }

    /** @test */
    public function transfer_without_bank_is_blocked_as_missing_bank(): void
    {
        $row = $this->rowFor($this->makeRefrend(['bank_id' => null, 'bank_name' => null]));

        $this->assertSame('TRANSFER', $row['payment_method']);
        $this->assertNull($row['bank_code']);
        $this->assertFalse($row['is_payable']);
        $this->assertContains('MISSING_BANK', array_column($row['blocking_reasons'], 'code'));
    }

    /** @test */
    public function every_becario_is_in_exactly_one_channel(): void
    {
        $bbva   = $this->makeRefrend([], [], 'A bbva');
        $other  = $this->makeRefrend($this->banorte(), [], 'B other');
        $legacy = $this->makeRefrend($this->banorte(['account_number' => '0123456789']), [], 'C legacy');
        $noBank = $this->makeRefrend(['bank_id' => null, 'bank_name' => null], [], 'D no bank');
        $cheque = $this->makeRefrend(['payment_method' => 'CHEQUE', 'bank_id' => null, 'bank_name' => null, 'account_number' => null], [], 'E cheque');
        $noData = $this->makeRefrend(null, [], 'F no data');

        [$transfer, $chequeRows, $manual] = $this->service->partitionByMethod(
            $this->service->rows(self::CAMPUS, self::YEAR, self::MONTH)
        );

        $ids = fn (array $rows) => array_column($rows, 'refrend_id');

        $this->assertEqualsCanonicalizing([$bbva->id, $noBank->id, $noData->id], $ids($transfer));
        $this->assertSame([$cheque->id], $ids($chequeRows));
        $this->assertEqualsCanonicalizing([$other->id, $legacy->id], $ids($manual));

        $all = array_merge($ids($transfer), $ids($chequeRows), $ids($manual));
        $this->assertCount(6, $all);
        $this->assertSame($all, array_values(array_unique($all)));
    }

    /** @test */
    public function paid_manual_transfer_snapshot_wins_over_the_current_bank(): void
    {
        $refrend = $this->makeRefrend([], [
            'status' => 'PAID', 'workflow_status' => 'CLOSED', 'locked_at' => now(),
            'paid_payment_method' => PaymentMethod::MANUAL_TRANSFER->value,
        ]);

        $this->assertSame('MANUAL_TRANSFER', $this->rowFor($refrend)['payment_method']);
    }

    /** @test */
    public function paid_rows_excludes_manual_transfers_and_exposes_the_bank_code(): void
    {
        $batch = ScholarshipPaymentBatch::create($this->batchAttributes());
        $bbva  = $this->makeRefrend([], ['payment_batch_id' => $batch->id, 'status' => 'PAID'], 'T');
        $this->makeRefrend($this->banorte(), [
            'payment_batch_id' => $batch->id, 'status' => 'PAID',
            'paid_payment_method' => PaymentMethod::MANUAL_TRANSFER->value,
        ], 'M');

        $rows = $this->service->paidRows($batch);

        $this->assertSame([$bbva->id], array_column($rows, 'refrend_id'));
        $this->assertSame('012', $rows[0]['bank_code']);
    }

    /** @test */
    public function paid_rows_exposes_a_null_bank_code_for_historical_rows_without_bank(): void
    {
        $batch = ScholarshipPaymentBatch::create($this->batchAttributes());
        $this->makeRefrend(['bank_id' => null, 'bank_name' => 'Legacy'], ['payment_batch_id' => $batch->id, 'status' => 'PAID']);

        $this->assertNull($this->service->paidRows($batch)[0]['bank_code']);
    }

    /** @test */
    public function a_manual_transfer_row_stamped_cert_unid_stays_in_that_segment(): void
    {
        $refrend = $this->makeRefrend($this->banorte(), [
            'status' => 'PAID', 'workflow_status' => 'CLOSED', 'locked_at' => now(),
            'paid_payment_method' => PaymentMethod::MANUAL_TRANSFER->value,
            'paid_batch_type'     => 'CERT_UNID',
        ]);

        $ids = fn (PaymentBatchType $type) => array_column(
            $this->service->rows(self::CAMPUS, self::YEAR, self::MONTH, null, $type),
            'refrend_id'
        );

        $this->assertSame([$refrend->id], $ids(PaymentBatchType::CERT_UNID));
        $this->assertSame([], $ids(PaymentBatchType::NORMAL));
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
