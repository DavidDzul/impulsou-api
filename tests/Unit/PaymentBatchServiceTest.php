<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
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
                'rfc'            => 'PEPJ800101ABC',
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
        $this->assertFalse($row['has_incident']);
        $this->assertFalse($row['has_pending_from_previous']);
    }

    // ── rfc selection + payment_batch_id emission (PR3) ─────────────────────

    /** @test */
    public function rows_selects_and_emits_rfc(): void
    {
        $this->makeReadyRefrend();

        $rows = $this->rows();

        $this->assertArrayHasKey('rfc', $rows[0]);
        $this->assertSame('PEPJ800101ABC', $rows[0]['rfc']);
    }

    /** @test */
    public function rows_emits_payment_batch_id_and_it_is_null_when_unpaid(): void
    {
        $this->makeReadyRefrend();

        $rows = $this->rows();

        $this->assertArrayHasKey('payment_batch_id', $rows[0]);
        $this->assertNull($rows[0]['payment_batch_id']);
    }

    /** @test */
    public function rows_emits_the_actual_payment_batch_id_when_already_paid(): void
    {
        $batch = ScholarshipPaymentBatch::create([
            'generation_id'   => self::GENERATION_ID,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => User::factory()->create(['user_type' => 'ADMIN'])->id,
            'processed_at'    => now(),
        ]);
        $this->makeReadyRefrend([
            'payment_batch_id' => $batch->id,
            'status'           => RefrendStatus::PAID->value,
            'workflow_status'  => 'CLOSED',
            'locked_at'        => now(),
        ]);

        $rows = $this->rows();

        $this->assertSame($batch->id, $rows[0]['payment_batch_id']);
    }

    /** @test */
    public function rows_passes_account_number_and_rfc_to_the_evaluator_so_a_malformed_rfc_blocks_payment(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => self::CAMPUS, 'active' => true]);
        ScholarshipPaymentData::create([
            'user_id'        => $user->id,
            'bank_name'      => 'BBVA',
            'account_number' => '0123456789',
            'curp'           => 'CURP010101HDFXXX01',
            'rfc'            => '1234800101ABC', // non-alphabetic first 4 chars — structurally invalid
        ]);
        ScholarshipRefrend::create([
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
        ]);

        $rows = $this->rows();

        $this->assertFalse($rows[0]['is_payable']);
        $this->assertContains('INVALID_RFC', array_column($rows[0]['blocking_reasons'], 'code'));
    }

    // ── Quick-glance indicators (has_incident / has_pending_from_previous) ────

    /** @test */
    public function has_incident_is_true_when_the_refrend_has_an_unresolved_incident(): void
    {
        $refrend = $this->makeReadyRefrend();
        $refrend->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'INASISTENCIA',
            'incident_date'     => '2026-05-10',
            'description'       => 'Faltó a clase sin justificación.',
            'is_resolved'       => false,
        ]);

        $rows = $this->rows();

        $this->assertTrue($rows[0]['has_incident']);
    }

    /** @test */
    public function has_incident_is_false_when_the_refrend_has_zero_incidents(): void
    {
        $this->makeReadyRefrend();

        $rows = $this->rows();

        $this->assertFalse($rows[0]['has_incident']);
    }

    /** @test */
    public function has_pending_from_previous_is_true_when_amount_pending_from_previous_is_greater_than_zero(): void
    {
        $this->makeReadyRefrend([
            'amount_pending_from_previous' => 200.00,
        ]);

        $rows = $this->rows();

        $this->assertTrue($rows[0]['has_pending_from_previous']);
    }

    /** @test */
    public function has_pending_from_previous_is_false_when_amount_pending_from_previous_is_zero(): void
    {
        $this->makeReadyRefrend([
            'amount_pending_from_previous' => 0,
        ]);

        $rows = $this->rows();

        $this->assertFalse($rows[0]['has_pending_from_previous']);
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

    // ── paidRows() (PR3): sourced exclusively from $batch->refrends ────────

    private function makeBatch(array $overrides = []): ScholarshipPaymentBatch
    {
        return ScholarshipPaymentBatch::create(array_merge([
            'generation_id'   => self::GENERATION_ID,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => User::factory()->create(['user_type' => 'ADMIN'])->id,
            'processed_at'    => now(),
        ], $overrides));
    }

    private function makePaidRefrendForBatch(
        ScholarshipPaymentBatch $batch,
        array $refrendOverrides = [],
        array $userOverrides = [],
        bool $withPaymentData = true
    ): ScholarshipRefrend {
        $refrend = $this->makeReadyRefrend($refrendOverrides, $userOverrides, $withPaymentData);
        $refrend->update([
            'payment_batch_id' => $batch->id,
            'status'           => RefrendStatus::PAID->value,
            'workflow_status'  => 'CLOSED',
            'locked_at'        => now(),
        ]);

        return $refrend->fresh();
    }

    /** @test */
    public function paid_rows_returns_the_expected_shape(): void
    {
        $batch   = $this->makeBatch();
        $refrend = $this->makePaidRefrendForBatch($batch);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame($refrend->id, $row['refrend_id']);
        $this->assertSame($refrend->user_id, $row['user_id']);
        $this->assertSame('Test Becario', $row['snapshot_name']);
        $this->assertSame('PEPJ800101ABC', $row['rfc']);
        $this->assertSame('0123456789', $row['account_number']);
        $this->assertSame('1000.00', $row['total_to_pay']);
    }

    /** @test */
    public function paid_rows_sources_from_the_refrends_relation_not_the_stale_aggregate_columns(): void
    {
        // The batch's own refrend_count/total_amount OVERSTATE the real
        // FK-linked set (captured pre-lock; one refrend was skipped inside
        // BulkPayAction under the row lock and never got payment_batch_id
        // stamped) — paidRows() must never trust those columns.
        $batch = $this->makeBatch([
            'refrend_count' => 5,
            'total_amount'  => '5000.00',
        ]);
        $this->makePaidRefrendForBatch($batch);
        // Not stamped with this batch's id — simulates a skipped/discharged becario.
        $this->makeReadyRefrend(['workflow_status' => 'PENDIENTE_NOTIFICACION']);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows, 'paidRows() must source rows exclusively from $batch->refrends(), never refrend_count/total_amount.');
    }

    /** @test */
    public function paid_rows_excludes_a_refrend_linked_to_a_different_batch(): void
    {
        $batch      = $this->makeBatch();
        $otherBatch = $this->makeBatch(['period_month' => self::MONTH + 1]);

        $this->makePaidRefrendForBatch($batch);
        $this->makePaidRefrendForBatch($otherBatch);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
    }

    /** @test */
    public function paid_rows_is_empty_for_a_batch_with_zero_linked_refrends(): void
    {
        $batch = $this->makeBatch(['refrend_count' => 0, 'total_amount' => '0.00']);

        $rows = $this->service->paidRows($batch);

        $this->assertSame([], $rows);
    }

    /** @test */
    public function paid_rows_orders_by_snapshot_name(): void
    {
        $batch = $this->makeBatch(['refrend_count' => 2, 'total_amount' => '2000.00']);
        $this->makePaidRefrendForBatch($batch, ['snapshot_name' => 'Zulema Test']);
        $this->makePaidRefrendForBatch($batch, ['snapshot_name' => 'Ana Test']);

        $rows = $this->service->paidRows($batch);

        $this->assertSame(['Ana Test', 'Zulema Test'], array_column($rows, 'snapshot_name'));
    }

    // ── resolution_type / resolution_cause (sdd/resolution-status-visibility) ──
    //
    // THE regression gate (design "Testing Strategy", task 1.1): literals
    // below were captured from this service's ACTUAL behavior BEFORE
    // 'resolution_type'/'resolution_cause' were added to rows()'s SELECT or
    // returned shape — not invented. This test must keep passing, byte
    // identical, after PaymentBatchService::rows() gains the two new keys
    // (task 1.5). It is the mechanical proof of the user's core requirement:
    // the resolution indicator is purely additive and NEVER changes
    // is_payable/blocking_reasons/total_to_pay/summary.
    //
    // Row 1: partial RETENIDA — status=WITHHELD but final_amount is positive,
    // so NOTHING_TO_PAY does NOT fire (PaymentReadinessEvaluator:68 requires
    // final_amount<=0). Payable.
    // Row 2: SIN_PAGO — final_amount='0.00' but status is left at the
    // default 'DRAFT' (untouched by resolution), so the WITHHELD-specific
    // NOTHING_TO_PAY check never runs; BankDataValidator's own
    // NOTHING_TO_PAY reason for the same zero amount is explicitly discarded
    // by the evaluator (PaymentReadinessEvaluator:85-91) regardless. Payable
    // with $0.00 — exactly the "blind spot" this whole change makes visible
    // (design D5).
    // Row 3: BECA_MES — ordinary ready refrend, resolution_type set but
    // nothing else changed. Payable.
    // Row 4: null resolution_type — the ordinary bulk-approve path. Payable.
    /** @test */
    public function resolution_type_and_resolution_cause_never_alter_is_payable_blocking_reasons_total_to_pay_or_summary(): void
    {
        $this->makeReadyRefrend([
            'status'           => 'WITHHELD',
            'final_amount'     => 300.00,
            'resolution_type'  => 'RETENIDA',
            'resolution_cause' => 'BAJO_PROMEDIO',
        ]);
        $this->makeReadyRefrend([
            'final_amount'     => 0.00,
            'resolution_type'  => 'SIN_PAGO',
            'resolution_cause' => null,
        ]);
        $this->makeReadyRefrend([
            'resolution_type'  => 'BECA_MES',
            'resolution_cause' => null,
        ]);
        $this->makeReadyRefrend();

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertCount(4, $rows);

        $captured = array_map(
            fn (array $row) => [
                'is_payable'       => $row['is_payable'],
                'blocking_reasons' => $row['blocking_reasons'],
                'total_to_pay'     => $row['total_to_pay'],
            ],
            $rows
        );

        $this->assertSame([
            ['is_payable' => true, 'blocking_reasons' => [], 'total_to_pay' => '300.00'],
            ['is_payable' => true, 'blocking_reasons' => [], 'total_to_pay' => '0.00'],
            ['is_payable' => true, 'blocking_reasons' => [], 'total_to_pay' => '1000.00'],
            ['is_payable' => true, 'blocking_reasons' => [], 'total_to_pay' => '1000.00'],
        ], $captured);

        $this->assertSame([
            'total'        => 4,
            'ready'        => 4,
            'blocking'     => 0,
            'total_amount' => '2300.00',
        ], $summary);
    }

    // 8+1 pass-through matrix (task 1.2): one refrend per resolution_type
    // value verified against ScholarshipRefrendController.php:334's
    // validation rule, plus a null row. BECA_MES is asserted PRESENT, not
    // excluded (user override of the proposal's D5 exclusion). array_keys()
    // is checked exactly so a 9th, unexpected key never silently appears.
    /** @test */
    public function rows_passes_resolution_type_and_resolution_cause_through_unchanged_for_all_eight_values_and_null(): void
    {
        $values = [
            'BECA_MES', 'SIN_PAGO', 'RETENIDA', 'SUSPENDIDA',
            'BAJA_DEFINITIVA', 'EGRESADO', 'REEMBOLSO_PARCIAL', 'DESCUENTO_DEFINITIVO',
        ];

        foreach ($values as $value) {
            $this->makeReadyRefrend([
                'snapshot_name'    => "Becario {$value}",
                'resolution_type'  => $value,
                'resolution_cause' => "{$value}_CAUSE",
            ]);
        }
        $this->makeReadyRefrend([
            'snapshot_name'    => 'Becario NULL',
            'resolution_type'  => null,
            'resolution_cause' => null,
        ]);

        $rows = collect($this->rows())->keyBy('snapshot_name');

        $this->assertCount(9, $rows);

        foreach ($values as $value) {
            $row = $rows["Becario {$value}"];
            $this->assertSame($value, $row['resolution_type']);
            $this->assertSame("{$value}_CAUSE", $row['resolution_cause']);
        }

        $nullRow = $rows['Becario NULL'];
        $this->assertNull($nullRow['resolution_type']);
        $this->assertNull($nullRow['resolution_cause']);

        $expectedKeys = [
            'refrend_id', 'user_id', 'snapshot_name', 'enrollment', 'bank_name',
            'account_number', 'rfc', 'payment_batch_id', 'total_to_pay', 'is_payable',
            'blocking_reasons', 'outcome', 'outcome_reason', 'has_incident',
            'has_pending_from_previous', 'resolution_type', 'resolution_cause',
        ];
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($rows['Becario BECA_MES']));
    }

    // Boundary-lock (task 1.3): paidRows() has its own independent SELECT
    // list for the bank-file export and must NEVER gain these two keys, so a
    // later contributor does not "helpfully" mirror this change into the
    // export path (design D1's explicit scope boundary).
    /** @test */
    public function paid_rows_never_exposes_resolution_type_or_resolution_cause(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'resolution_type'  => 'RETENIDA',
            'resolution_cause' => 'BAJO_PROMEDIO',
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('resolution_type', $rows[0]);
        $this->assertArrayNotHasKey('resolution_cause', $rows[0]);
    }
}
