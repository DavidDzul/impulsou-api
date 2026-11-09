<?php

namespace Tests\Feature;

use App\Enums\PaymentBatchType;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Bank;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lotes de pago: once the batch is PAID, index()'s `summary` money keys are
 * computed over the rows paid IN that batch (the set the bank files carry),
 * not over `ready` rows (which are all ALREADY_PAID, hence $0). `basis`
 * ('ready' | 'paid') tells the panel which one it is looking at, and `paid`
 * counts the rows of the paid basis.
 */
class ScholarshipPaymentPaidSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    private function indexUrl(): string
    {
        return '/api/admin/scholarship-payments?' . http_build_query([
            'campus'       => self::CAMPUS,
            'period_year'  => self::YEAR,
            'period_month' => self::MONTH,
            'batch_type'   => PaymentBatchType::NORMAL->value,
        ]);
    }

    private function newBatch(): ScholarshipPaymentBatch
    {
        return ScholarshipPaymentBatch::create([
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 999,
            'total_amount'    => '999999.99',
            'processed_by_id' => $this->rootAdmin->id,
            'processed_at'    => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function refrend(?ScholarshipPaymentBatch $batch, array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => self::CAMPUS,
            'active'     => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ]);
        ScholarshipPaymentData::create([
            'user_id'        => $user->id,
            'bank_id'        => Bank::where('code', '012')->value('id'),
            'bank_name'      => 'BBVA',
            'account_number' => '0123456789',
            'curp'           => 'CURP010101HDFXXX01',
            'rfc'            => 'PEPJ800101ABC',
        ]);

        $paid = $batch !== null ? [
            'status'           => RefrendStatus::PAID->value,
            'workflow_status'  => 'CLOSED',
            'locked_at'        => now(),
            'locked_by_id'     => $this->rootAdmin->id,
            'payment_batch_id' => $batch->id,
        ] : [
            'status'          => 'DRAFT',
            'workflow_status' => 'LISTO_PARA_PAGO',
        ];

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ] + $paid, $overrides));
    }

    /** apoyo + temporary increase + floored row + carryover + a Telmex row excluded from the files. */
    private function richPaidBatch(): ScholarshipPaymentBatch
    {
        $batch = $this->newBatch();

        $this->refrend($batch, [
            'snapshot_name' => 'A Apoyo', 'base_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00,
            'snapshot_gross_amount' => 2200.00, 'discount_amount' => 500.00, 'final_amount' => 1700.00,
        ]);
        $this->refrend($batch, [
            'snapshot_name' => 'B Incremento', 'base_amount' => 1000.00,
            'snapshot_temporary_increase_amount' => 300.00, 'snapshot_temporary_increase_reason' => 'Prueba',
            'final_amount' => 1300.00,
        ]);
        $this->refrend($batch, [
            'snapshot_name' => 'C Piso', 'base_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00,
            'snapshot_gross_amount' => 2200.00, 'discount_amount' => 2200.00, 'final_amount' => 0.00,
        ]);
        $this->refrend($batch, [
            'snapshot_name' => 'D Arrastre', 'final_amount' => 1000.00, 'amount_pending_from_previous' => 250.00,
        ]);
        $this->refrend($batch, [
            'snapshot_name' => 'E Telmex', 'snapshot_scholarship_type' => ScholarshipType::TELMEX->value,
            'base_amount' => 0.00, 'final_amount' => 0.00,
        ]);

        return $batch;
    }

    /** @test */
    public function a_paid_batch_summary_reports_what_was_paid_not_zero(): void
    {
        $this->richPaidBatch();

        $response = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.batch.is_paid'));

        $summary = $response->json('data.summary');
        $this->assertSame('paid', $summary['basis']);
        $this->assertSame(4, $summary['paid'], 'the Telmex row excluded from the bank files is not counted');
        // 1700 + 1300 + 0.01 (floor) + 1250
        $this->assertSame('4250.01', $summary['total_amount']);
        $this->assertSame('6000.00', $summary['beca_amount']);
        $this->assertSame('400.00', $summary['apoyo_amount']);
        $this->assertSame('0.00', $summary['pago_iu_amount']);
        foreach (['net_beca_amount', 'net_apoyo_amount', 'net_pago_iu_amount', 'net_temporary_increase_amount',
                  'net_telmex_coverage_amount', 'pending_and_advance_amount'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
        $this->assertSame('250.00', $summary['pending_and_advance_amount']);
    }

    /** @test */
    public function the_paid_summary_reconciles_with_the_export_summary_and_both_bank_files(): void
    {
        $batch = $this->richPaidBatch();

        $summary = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl())->json('data.summary');
        $export  = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/scholarship-payments/batches/{$batch->id}/export/summary")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($export['total_amount'], $summary['total_amount']);
        $this->assertSame($export['count'], $summary['paid']);
        $this->assertSame(
            number_format((float) $export['files']['beca']['total_amount'] + (float) $export['files']['apoyo']['total_amount'], 2, '.', ''),
            $summary['total_amount']
        );
    }

    /** @test */
    public function rows_that_were_not_paid_in_the_batch_do_not_enter_the_paid_summary(): void
    {
        $this->richPaidBatch();
        $this->refrend(null, ['snapshot_name' => 'Z Tarde', 'final_amount' => 777.00]);

        $summary = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl())->json('data.summary');

        $this->assertSame('paid', $summary['basis']);
        $this->assertSame(4, $summary['paid']);
        $this->assertSame('4250.01', $summary['total_amount']);
    }

    /** @test */
    public function an_unpaid_period_keeps_the_ready_basis(): void
    {
        $this->refrend(null, ['final_amount' => 1000.00]);
        $this->refrend(null, ['final_amount' => 1000.00]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());

        $this->assertFalse($response->json('data.batch.is_paid'));
        $summary = $response->json('data.summary');
        $this->assertSame('ready', $summary['basis']);
        $this->assertSame(0, $summary['paid']);
        $this->assertSame(2, $summary['ready']);
        $this->assertSame('2000.00', $summary['total_amount']);
    }

    /** @test */
    public function processing_a_batch_flips_the_summary_from_ready_to_paid_with_the_same_total(): void
    {
        $this->refrend(null, ['final_amount' => 1000.00]);
        $this->refrend(null, ['final_amount' => 1000.00]);

        $before = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl())->json('data.summary');

        $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-payments/process', [
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH,
            'batch_type' => PaymentBatchType::NORMAL->value, 'expected_count' => 2, 'expected_total' => '2000.00',
        ])->assertStatus(200);

        $after = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl())->json('data.summary');

        $this->assertSame('ready', $before['basis']);
        $this->assertSame('paid', $after['basis']);
        $this->assertSame(2, $after['paid']);
        $this->assertSame($before['total_amount'], $after['total_amount']);
        $this->assertSame($before['beca_amount'], $after['beca_amount']);
    }
}
