<?php

namespace Tests\Unit;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for PaymentBatchService.
 *
 * Mirrors RefrendBulkQueryServiceTest's fixture conventions (plain
 * ScholarshipRefrend::create(), User::factory()) and cross-checks
 * total_to_pay against the same fixture numbers used in
 * RefrendBulkQueryServiceTest::total_to_pay_equals_final_amount_plus_amount_pending_from_previous
 * (final_amount=1000.00, amount_pending_from_previous=200.00 -> 1200.00),
 * per the task's "don't invent new expected numbers" instruction.
 */
class PaymentBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentBatchService $service;

    private const GENERATION_ID = 1;
    private const CAMPUS        = 'MERIDA';
    private const YEAR          = 2026;
    private const MONTH         = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PaymentBatchService::class);
    }

    /**
     * Creates a becario user + refrend inside the default batch key, with
     * full readiness (LISTO_PARA_PAGO, unlocked, enrollment, bank data) by
     * default so overrides can flip one thing at a time.
     */
    private function makeReadyRefrend(array $refrendOverrides = [], array $userOverrides = [], bool $withPaymentData = true): ScholarshipRefrend
    {
        $user = User::factory()->create(array_merge([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => self::CAMPUS,
            'active'    => true,
        ], $userOverrides));

        if ($withPaymentData) {
            ScholarshipPaymentData::create([
                'user_id'        => $user->id,
                'bank_name'      => 'BBVA',
                'account_number' => '0123456789',
                'curp'           => 'CURP010101HDFXXX01',
                'rfc'            => 'RFC010101ABC',
            ]);
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
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => self::GENERATION_ID,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $refrendOverrides));
    }

    private function rows(): array
    {
        return $this->service->rows(self::GENERATION_ID, self::CAMPUS, self::YEAR, self::MONTH);
    }

    // ── Row shape ────────────────────────────────────────────────────────────

    /** @test */
    public function rows_returns_the_full_payment_batch_row_shape_for_a_ready_becario(): void
    {
        $refrend = $this->makeReadyRefrend();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame($refrend->id, $row['refrend_id']);
        $this->assertSame($refrend->user_id, $row['user_id']);
        $this->assertSame('Test Becario', $row['snapshot_name']);
        $this->assertNotNull($row['enrollment']);
        $this->assertSame('BBVA', $row['bank_name']);
        $this->assertSame('0123456789', $row['account_number']);
        $this->assertSame('1000.00', $row['total_to_pay']);
        $this->assertTrue($row['is_payable']);
        $this->assertSame([], $row['blocking_reasons']);
        $this->assertNull($row['outcome']);
        $this->assertNull($row['outcome_reason']);
    }

    // ── Joins ────────────────────────────────────────────────────────────────

    /** @test */
    public function becario_with_no_payment_data_row_still_appears_with_null_bank_fields(): void
    {
        $this->makeReadyRefrend(withPaymentData: false);

        $rows = $this->rows();

        $this->assertCount(1, $rows, 'A becario missing scholarship_payment_data must NOT be excluded from the query.');
        $this->assertNull($rows[0]['bank_name']);
        $this->assertNull($rows[0]['account_number']);
        $this->assertFalse($rows[0]['is_payable']);
        $this->assertContains('MISSING_PAYMENT_DATA', array_column($rows[0]['blocking_reasons'], 'code'));
    }

    /** @test */
    public function becario_with_no_enrollment_is_blocked_but_still_appears(): void
    {
        $this->makeReadyRefrend([], ['enrollment' => null]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['enrollment']);
        $this->assertFalse($rows[0]['is_payable']);
        $this->assertContains('MISSING_ENROLLMENT', array_column($rows[0]['blocking_reasons'], 'code'));
    }

    // ── Filtering by batch key (D1) ─────────────────────────────────────────

    /** @test */
    public function rows_excludes_refrends_outside_the_batch_key(): void
    {
        $this->makeReadyRefrend();
        $this->makeReadyRefrend(['period_month' => self::MONTH + 1]);
        $this->makeReadyRefrend(['snapshot_campus' => 'CANCUN']);
        $this->makeReadyRefrend(['snapshot_generation_id' => self::GENERATION_ID + 1]);

        $rows = $this->rows();

        $this->assertCount(1, $rows, 'Only refrends matching generation_id + campus + period_year + period_month must be returned.');
    }

    // ── total_to_pay formula (cross-checked against RefrendBulkQueryServiceTest fixtures) ──

    /** @test */
    public function total_to_pay_includes_amount_pending_from_previous(): void
    {
        $this->makeReadyRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 200.00,
        ]);

        $rows = $this->rows();

        $this->assertSame('1200.00', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function total_to_pay_includes_refund_amount_from_previous(): void
    {
        $this->makeReadyRefrend([
            'final_amount'                 => 800.00,
            'amount_pending_from_previous' => 0,
            'refund_amount_from_previous'  => 50.00,
        ]);

        $rows = $this->rows();

        $this->assertSame('850.00', $rows[0]['total_to_pay']);
    }

    // ── Summary ──────────────────────────────────────────────────────────────

    /** @test */
    public function summary_counts_and_sums_only_ready_rows_in_a_mixed_batch(): void
    {
        // 3 ready (1000.00 each), 2 blocking (not approved / missing enrollment).
        $this->makeReadyRefrend();
        $this->makeReadyRefrend();
        $this->makeReadyRefrend();
        $this->makeReadyRefrend(['workflow_status' => 'PENDIENTE_NOTIFICACION']);
        $this->makeReadyRefrend([], ['enrollment' => null]);

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertSame(5, $summary['total']);
        $this->assertSame(3, $summary['ready']);
        $this->assertSame(2, $summary['blocking']);
        $this->assertSame('3000.00', $summary['total_amount']);
    }

    /** @test */
    public function summary_is_all_zero_for_an_empty_batch(): void
    {
        $summary = $this->service->summary([]);

        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['ready']);
        $this->assertSame(0, $summary['blocking']);
        $this->assertSame('0.00', $summary['total_amount']);
    }
}
