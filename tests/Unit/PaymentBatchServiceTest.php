<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
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
        return $this->service->rows(self::CAMPUS, self::YEAR, self::MONTH);
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

    /**
     * Distinguishes "this payment settles a retained month on top of a
     * normal current-month payment" from "this payment settles ONLY a
     * retained month, the current month pays nothing" — the frontend chip
     * previously read the same in both cases (user-reported ambiguity).
     */
    /** @test */
    public function only_pending_from_previous_is_true_when_current_month_pays_nothing(): void
    {
        $this->makeReadyRefrend([
            'final_amount'                 => 0,
            'amount_pending_from_previous' => 300.00,
        ]);

        $rows = $this->rows();

        $this->assertTrue($rows[0]['only_pending_from_previous']);
    }

    /** @test */
    public function only_pending_from_previous_is_false_when_current_month_also_pays(): void
    {
        $this->makeReadyRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 300.00,
        ]);

        $rows = $this->rows();

        $this->assertFalse($rows[0]['only_pending_from_previous']);
    }

    /** @test */
    public function only_pending_from_previous_is_false_when_there_is_nothing_pending_from_previous(): void
    {
        $this->makeReadyRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
        ]);

        $rows = $this->rows();

        $this->assertFalse($rows[0]['only_pending_from_previous']);
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

    // ── Filtering by batch key (sdd/pagos-batch-sede-totals: campus + period only) ──

    /** @test */
    public function rows_excludes_refrends_outside_the_campus_and_period_batch_key(): void
    {
        $this->makeReadyRefrend();
        $this->makeReadyRefrend(['period_month' => self::MONTH + 1]);
        $this->makeReadyRefrend(['snapshot_campus' => 'CANCUN']);

        $rows = $this->rows();

        $this->assertCount(1, $rows, 'Only refrends matching campus + period_year + period_month must be returned — generation_id no longer narrows the key.');
    }

    /**
     * INVERTED (sdd/pagos-batch-sede-totals): previously a different
     * generation_id excluded a refrend from the batch (one batch per
     * generación per sede). The batch key is now campus + period only, so
     * a refrend from a DIFFERENT generación at the SAME campus+period
     * MUST now be included — a batch spans multiple generaciones.
     *
     * @test
     */
    public function rows_includes_a_refrend_from_a_different_generation_at_the_same_campus_and_period(): void
    {
        $this->makeReadyRefrend();
        $this->makeReadyRefrend(['snapshot_generation_id' => self::GENERATION_ID + 1]);

        $rows = $this->rows();

        $this->assertCount(2, $rows, 'A batch must span multiple generaciones at the same campus+period — generation_id must not narrow the key.');
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

    // ── sdd/pago-adelantado D6: total_to_pay += advance_payment_amount ───────
    // This is the THIRD of the three lockstep formula sites (the other two
    // are ScholarshipRefrendTotalToPayTest and RefrendBulkQueryServiceTest).

    /** @test */
    public function total_to_pay_is_unchanged_when_advance_payment_amount_defaults_to_zero(): void
    {
        // No explicit advance_payment_amount override — relies on the
        // column's NOT NULL DEFAULT 0, the exact state of every pre-PR5 row.
        $this->makeReadyRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 200.00,
        ]);

        $rows = $this->rows();

        $this->assertSame('1200.00', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function total_to_pay_includes_advance_payment_amount(): void
    {
        $this->makeReadyRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'advance_payment_amount'       => 3000.00,
        ]);

        $rows = $this->rows();

        $this->assertSame('4000.00', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function paid_rows_total_to_pay_also_includes_advance_payment_amount(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'advance_payment_amount'       => 3000.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertSame('4000.00', $rows[0]['total_to_pay']);
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
    // (design D5). UPDATED (sdd/bank-file-minimum-deposit): this IU row's
    // total_to_pay is now floored to '0.01' by PaymentAmountFloor::apply()
    // inside rows() — the literal below was deliberately bumped from '0.00'
    // to '0.01' (and the summary total_amount from '2300.00' to '2300.01')
    // to reflect the new intended behavior, NOT a regression. The floor's
    // own isolation (never altering is_payable/blocking_reasons) is what
    // this test still locks.
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
            ['is_payable' => true, 'blocking_reasons' => [], 'total_to_pay' => '0.01'],
            ['is_payable' => true, 'blocking_reasons' => [], 'total_to_pay' => '1000.00'],
            ['is_payable' => true, 'blocking_reasons' => [], 'total_to_pay' => '1000.00'],
        ], $captured);

        // beca_amount/apoyo_amount/pago_iu_amount/difference_amount
        // (sdd/pagos-batch-sede-totals, design D9-D12) are a side effect of
        // this test's fixtures, all defaulting to IU/base_amount=1000.00/
        // snapshot_monto_apoyo=null via makeReadyRefrend() — not evidence
        // about resolution_type/resolution_cause, which this test exists to
        // lock. The point under test (is_payable/blocking_reasons/
        // total_to_pay/total_amount unaffected by resolution_type) still
        // holds; these 4 new keys are included only so the assertion stays
        // an exact, non-partial match.
        $this->assertSame([
            'total'             => 4,
            'ready'             => 4,
            'blocking'          => 0,
            'beca_amount'       => '4000.00',
            'apoyo_amount'      => '0.00',
            'pago_iu_amount'    => '0.00',
            'total_amount'      => '2300.01',
            'difference_amount' => '1699.99',
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
            'has_pending_from_previous', 'only_pending_from_previous', 'resolution_type', 'resolution_cause',
            'advance_paid', 'advance_paid_amount', 'advance_paid_origin_year', 'advance_paid_origin_month',
            'advance_paid_divergence_reason', 'advance_payment_amount',
            // sdd/scholarship-telmex-iu-split, design D9:
            'excluded_from_bank_file',
            // sdd/temporary-increase-visibility, design D7:
            'snapshot_temporary_increase_amount', 'snapshot_temporary_increase_reason',
            // sdd/pagos-batch-sede-totals, design D9 (Part 2 — PR3): raw
            // pass-through type-partition fields, purely informational.
            'snapshot_scholarship_type', 'base_amount', 'snapshot_monto_apoyo',
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

    // ── sdd/pago-adelantado: advance-paid row indicator (rows() only) ────────
    // Spec "Row indicator in administration-panel Pagos table". Purely
    // informational — never touches is_payable/blocking_reasons.

    private function linkAdvancePaidRefrend(
        ScholarshipRefrend $refrend,
        int $originYear,
        int $originMonth,
        float $amount = 500.00,
        ?string $divergenceReason = null
    ): void {
        $originRefrend = ScholarshipRefrend::create([
            'user_id'       => $refrend->user_id,
            'period_year'   => $originYear,
            'period_month'  => $originMonth,
            'base_amount'   => 1000,
            'final_amount'  => 1000,
            'snapshot_name' => 'Origin Refrend',
        ]);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $refrend->user_id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => $originYear,
            'origin_period_month' => $originMonth,
            'months_count'        => 1,
            'total_amount'        => $amount,
        ]);

        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id'      => $header->id,
            'user_id'                 => $refrend->user_id,
            'period_year'             => $refrend->period_year,
            'period_month'            => $refrend->period_month,
            'amount'                  => $amount,
            'refrend_id'              => $refrend->id,
            'status'                  => $divergenceReason !== null ? 'OVERRIDDEN' : 'PENDING',
            'settled_resolution_type' => $divergenceReason !== null ? 'BECA_MES' : null,
            'divergence_reason'       => $divergenceReason,
        ]);
    }

    /** @test */
    public function advance_paid_is_false_and_fields_are_null_for_a_normal_row(): void
    {
        $this->makeReadyRefrend();

        $rows = $this->rows();

        $this->assertFalse($rows[0]['advance_paid']);
        $this->assertNull($rows[0]['advance_paid_amount']);
        $this->assertNull($rows[0]['advance_paid_origin_year']);
        $this->assertNull($rows[0]['advance_paid_origin_month']);
        $this->assertNull($rows[0]['advance_paid_divergence_reason']);
    }

    /** @test */
    public function advance_paid_is_true_with_origin_month_year_and_amount_when_linked(): void
    {
        $refrend = $this->makeReadyRefrend();
        $this->linkAdvancePaidRefrend($refrend, 2026, 2, 500.00);

        $rows = $this->rows();

        $this->assertTrue($rows[0]['advance_paid']);
        $this->assertSame('500.00', $rows[0]['advance_paid_amount']);
        $this->assertSame(2026, $rows[0]['advance_paid_origin_year']);
        $this->assertSame(2, $rows[0]['advance_paid_origin_month']);
        $this->assertNull($rows[0]['advance_paid_divergence_reason']);
    }

    // Row-level chip data for the divergence-reason indicator
    // (administration-panel, added 2026-09-27) — staff overrode the safe
    // $0 outcome with a reason, so a reviewer scanning the batch table
    // needs to see that at a glance, not only inside the "Ver" document.
    /** @test */
    public function advance_paid_divergence_reason_is_present_when_the_arrived_month_was_settled_with_a_reason(): void
    {
        $refrend = $this->makeReadyRefrend();
        $this->linkAdvancePaidRefrend($refrend, 2026, 2, 500.00, 'Autorizado por dirección.');

        $rows = $this->rows();

        $this->assertTrue($rows[0]['advance_paid']);
        $this->assertSame('Autorizado por dirección.', $rows[0]['advance_paid_divergence_reason']);
    }

    /** @test */
    public function advance_paid_never_alters_is_payable_or_blocking_reasons(): void
    {
        $refrend = $this->makeReadyRefrend();
        $this->linkAdvancePaidRefrend($refrend, 2026, 2, 500.00);

        $rows = $this->rows();

        $this->assertTrue($rows[0]['is_payable']);
        $this->assertSame([], $rows[0]['blocking_reasons']);
    }

    // Boundary-lock (mirrors paid_rows_never_exposes_resolution_type_or_resolution_cause):
    // paidRows() must NEVER gain the advance_paid indicator fields, so a
    // later contributor does not "helpfully" mirror this change into the
    // export path (design's explicit scope boundary — the export path only
    // needs the money term, already wired into totalToPay()).
    /** @test */
    public function paid_rows_never_exposes_advance_paid_indicator_fields(): void
    {
        $batch   = $this->makeBatch();
        $refrend = $this->makePaidRefrendForBatch($batch);
        $this->linkAdvancePaidRefrend($refrend, 2026, 2, 500.00);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('advance_paid', $rows[0]);
        $this->assertArrayNotHasKey('advance_paid_amount', $rows[0]);
        $this->assertArrayNotHasKey('advance_paid_origin_year', $rows[0]);
        $this->assertArrayNotHasKey('advance_paid_origin_month', $rows[0]);
        $this->assertArrayNotHasKey('advance_paid_divergence_reason', $rows[0]);
    }

    // Boundary-lock (sdd/temporary-increase-visibility, task 1.7): paidRows()
    // must NEVER gain these two keys either — the export path only needs the
    // money term, already wired into totalToPay(), same explicit scope
    // boundary as resolution_type/advance_paid above.
    /** @test */
    public function paid_rows_never_exposes_snapshot_temporary_increase_amount_or_reason(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_temporary_increase_amount' => 500.00,
            'snapshot_temporary_increase_reason' => 'Ajuste especial autorizado por dirección.',
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('snapshot_temporary_increase_amount', $rows[0]);
        $this->assertArrayNotHasKey('snapshot_temporary_increase_reason', $rows[0]);
    }

    // ── PR8: advance_payment_amount as its own row field (origin-refrend indicator) ──
    //
    // Distinct from advance_paid/advance_paid_amount above: those describe
    // "this row IS one of the future months settled by someone else's
    // batch". This field describes the opposite direction — "this row
    // itself has an advance payment registered against it, i.e. it is the
    // ORIGIN refrend of a batch" (design D6's advance_payment_amount
    // column, already feeding totalToPay() below, but never emitted on its
    // own before now). Both can theoretically be true on different rows;
    // never conflate the two.

    /** @test */
    public function rows_emits_advance_payment_amount_as_its_own_field_when_positive(): void
    {
        $this->makeReadyRefrend([
            'advance_payment_amount' => 3000.00,
        ]);

        $rows = $this->rows();

        $this->assertArrayHasKey('advance_payment_amount', $rows[0]);
        $this->assertSame('3000.00', $rows[0]['advance_payment_amount']);
    }

    /** @test */
    public function rows_emits_advance_payment_amount_as_zero_string_when_column_defaults_to_zero(): void
    {
        // No explicit advance_payment_amount override — relies on the
        // column's NOT NULL DEFAULT 0.
        $this->makeReadyRefrend();

        $rows = $this->rows();

        $this->assertSame('0.00', $rows[0]['advance_payment_amount']);
    }

    // ── Filter A: candidacy (sdd/scholarship-telmex-iu-split, design D1/D3) ──

    /** @test */
    public function pure_telmex_without_an_active_increase_is_absent_from_rows(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'snapshot_temporary_increase_amount' => null,
        ]);

        $rows = $this->rows();

        $this->assertCount(0, $rows, 'A pure TELMEX row without an active increase has nothing payable and must not be a batch candidate.');
    }

    /** @test */
    public function pure_telmex_with_an_active_increase_is_present_at_the_increase_only_amount(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'snapshot_temporary_increase_amount' => 500.00,
            'final_amount'                       => 500.00,
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('500.00', $rows[0]['total_to_pay']);
        $this->assertTrue($rows[0]['is_payable']);
    }

    /** @test */
    public function telmex_iu_is_always_a_candidate_in_rows_regardless_of_increase(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX_IU->value,
            'snapshot_temporary_increase_amount' => null,
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
    }

    /** @test */
    public function filter_a_combines_correctly_with_the_batch_key_where_clauses(): void
    {
        // A TELMEX row without increase, IN the batch key, must be excluded;
        // an IU row OUTSIDE the batch key must also be excluded — Filter A's
        // grouped OR must not accidentally widen or narrow the existing
        // AND chain of batch-key where()s.
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'snapshot_temporary_increase_amount' => null,
        ]);
        $this->makeReadyRefrend(['period_month' => self::MONTH + 1]);
        $this->makeReadyRefrend();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
    }

    // ── excluded_from_bank_file chip (sdd/scholarship-telmex-iu-split, design D9) ──

    /** @test */
    public function excluded_from_bank_file_is_true_when_telmex_final_amount_is_zero(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'snapshot_temporary_increase_amount' => 500.00,
            'final_amount'                       => 0.00,
        ]);

        $rows = $this->rows();

        $this->assertTrue($rows[0]['excluded_from_bank_file']);
    }

    /** @test */
    public function excluded_from_bank_file_is_false_for_a_positive_telmex_row(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'snapshot_temporary_increase_amount' => 500.00,
            'final_amount'                       => 500.00,
        ]);

        $rows = $this->rows();

        $this->assertFalse($rows[0]['excluded_from_bank_file']);
    }

    /** @test */
    public function excluded_from_bank_file_is_false_for_a_zero_amount_iu_row(): void
    {
        // Never true for IU/TELMEX_IU — this chip predicts Filter B, which
        // only ever excludes TELMEX rows.
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'final_amount'              => 0.00,
        ]);

        $rows = $this->rows();

        $this->assertFalse($rows[0]['excluded_from_bank_file']);
    }

    /** @test */
    public function excluded_from_bank_file_never_influences_is_payable_or_blocking_reasons(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'snapshot_temporary_increase_amount' => 500.00,
            'final_amount'                       => 0.00,
        ]);

        $rows = $this->rows();

        $this->assertTrue($rows[0]['excluded_from_bank_file']);
        $this->assertTrue($rows[0]['is_payable'], 'excluded_from_bank_file is informational-only and must never gate is_payable.');
        $this->assertSame([], $rows[0]['blocking_reasons']);
    }

    // ── snapshot_temporary_increase_amount/_reason exposure (sdd/temporary-increase-visibility, design D7) ──
    //
    // Both columns already exist on scholarship_refrends and the amount was
    // already SELECTed (consumed internally by TelmexPaymentPolicy) — this
    // only locks that both fields now also survive into rows()'s returned
    // row shape. Purely informational, same convention as every other chip
    // field: MUST NOT be read by PaymentReadinessEvaluator or influence
    // is_payable/blocking_reasons, and MUST NOT touch has_incident /
    // scholarship_refrend_incidents (disproven-"Incidencia"-bug
    // non-regression — the two are unrelated data sources).

    /** @test */
    public function rows_passes_through_snapshot_temporary_increase_amount_and_reason_when_present(): void
    {
        $this->makeReadyRefrend([
            'snapshot_temporary_increase_amount' => 500.00,
            'snapshot_temporary_increase_reason' => 'Ajuste especial autorizado por dirección.',
        ]);

        $rows = $this->rows();

        // Raw pass-through (design D7/no casting applied) — assertEquals,
        // not assertSame: this column has numeric affinity in the sqlite
        // test driver, which returns it as a native int/float rather than a
        // formatted decimal string (unlike MySQL in production). The point
        // under test is that the VALUE survives unchanged, not its exact
        // PHP type.
        $this->assertEquals(500.00, $rows[0]['snapshot_temporary_increase_amount']);
        $this->assertSame('Ajuste especial autorizado por dirección.', $rows[0]['snapshot_temporary_increase_reason']);
    }

    /** @test */
    public function rows_returns_null_for_snapshot_temporary_increase_amount_and_reason_when_absent(): void
    {
        $this->makeReadyRefrend([
            'snapshot_temporary_increase_amount' => null,
            'snapshot_temporary_increase_reason' => null,
        ]);

        $rows = $this->rows();

        $this->assertNull($rows[0]['snapshot_temporary_increase_amount']);
        $this->assertNull($rows[0]['snapshot_temporary_increase_reason']);
    }

    /** @test */
    public function snapshot_temporary_increase_fields_never_influence_has_incident_or_is_payable(): void
    {
        // Regression lock: has_incident is driven exclusively by
        // scholarship_refrend_incidents rows, never by the snapshot
        // temporary-increase columns — the two are independent data
        // sources and must stay that way (the disproven-"Incidencia"-bug
        // hypothesis this non-regression test exists to prevent from
        // resurfacing).
        $this->makeReadyRefrend([
            'snapshot_temporary_increase_amount' => 500.00,
            'snapshot_temporary_increase_reason' => 'Ajuste especial autorizado por dirección.',
        ]);

        $rows = $this->rows();

        $this->assertFalse($rows[0]['has_incident']);
        $this->assertTrue($rows[0]['is_payable']);
        $this->assertSame([], $rows[0]['blocking_reasons']);
    }

    // ── Filter B: paidRows() exclusion (sdd/scholarship-telmex-iu-split, design D4) ──

    /** @test */
    public function paid_rows_excludes_a_zero_total_telmex_row(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type' => ScholarshipType::TELMEX->value,
            'final_amount'              => 0.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertSame([], $rows);
    }

    /** @test */
    public function paid_rows_excludes_only_the_zero_telmex_row_others_remain(): void
    {
        $batch = $this->makeBatch(['refrend_count' => 2, 'total_amount' => '1000.00']);
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_name'              => 'Becario IU',
            'snapshot_scholarship_type'  => ScholarshipType::IU->value,
            'final_amount'               => 1000.00,
        ]);
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_name'              => 'Becario Telmex Cero',
            'snapshot_scholarship_type'  => ScholarshipType::TELMEX->value,
            'final_amount'               => 0.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertSame('Becario IU', $rows[0]['snapshot_name']);
    }

    /** @test */
    public function paid_rows_never_excludes_a_zero_total_iu_row(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'final_amount'              => 0.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows, 'IU rows at total_to_pay<=0 are NOT Filter B\'s concern — they must remain so BankDataValidator can 422 the export.');
    }

    /** @test */
    public function paid_rows_never_excludes_a_zero_total_telmex_iu_row(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type' => ScholarshipType::TELMEX_IU->value,
            'final_amount'              => 0.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
    }

    /** @test */
    public function paid_rows_does_not_exclude_a_telmex_row_with_positive_pending_from_previous(): void
    {
        // Guards the final_amount-vs-total_to_pay mistake at the paidRows()
        // layer directly.
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type'    => ScholarshipType::TELMEX->value,
            'final_amount'                 => 0.00,
            'amount_pending_from_previous' => 300.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertSame('300.00', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function paid_rows_never_exposes_snapshot_scholarship_type_in_the_returned_shape(): void
    {
        // Filter B consumes it internally; it must not leak into the
        // returned row shape (which paidRows() has always kept minimal —
        // same boundary-lock convention as resolution_type/advance_paid).
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertArrayNotHasKey('snapshot_scholarship_type', $rows[0]);
    }

    // ── Shared payable-floor rule (sdd/bank-file-minimum-deposit, design D2) ──

    /** @test */
    public function rows_floors_a_zero_total_iu_row_to_one_cent(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'final_amount'              => 0.00,
        ]);

        $rows = $this->rows();

        $this->assertSame('0.01', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function rows_floors_a_zero_total_telmex_iu_row_to_one_cent(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::TELMEX_IU->value,
            'final_amount'              => 0.00,
        ]);

        $rows = $this->rows();

        $this->assertSame('0.01', $rows[0]['total_to_pay']);
    }

    /**
     * R1 regression lock (design risk R1, High severity) — a pure TELMEX
     * row with an active temporary increase fully consumed by a discount
     * (net total_to_pay = 0.00) must:
     *   (a) still show excluded_from_bank_file = true in rows()'s output,
     *       unaffected by the floor (chip NOT inverted); AND
     *   (b) still be silently absent from paidRows()'s output (Filter B
     *       unchanged, floor never applies).
     * Both assertions are locked in the SAME test to guard the cross-layer
     * contract as one unit.
     */
    /** @test */
    public function pure_telmex_row_fully_discounted_by_an_active_increase_keeps_the_exclusion_chip_true_and_stays_absent_from_the_export(): void
    {
        $batch = $this->makeBatch();
        $refrend = $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'snapshot_temporary_increase_amount'  => 500.00,
            'final_amount'                        => 0.00,
        ]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('0.00', $rows[0]['total_to_pay'], 'The floor must NOT apply to a pure TELMEX row — chip inversion guard.');
        $this->assertTrue($rows[0]['excluded_from_bank_file'], 'excluded_from_bank_file must stay true — the floor must never invert this chip (design R1).');

        $paidRows = $this->service->paidRows($batch);
        $this->assertSame([], $paidRows, 'The row must remain silently absent from paidRows() — Filter B is unaffected by the floor.');
    }

    /** @test */
    public function telmex_iu_with_an_active_increase_covering_the_discount_is_unaffected_by_the_floor(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX_IU->value,
            'snapshot_temporary_increase_amount' => 500.00,
            'final_amount'                       => 500.00,
        ]);

        $rows = $this->rows();

        $this->assertSame('500.00', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function paid_rows_floors_a_zero_total_iu_row_to_one_cent(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'final_amount'              => 0.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertSame('0.01', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function paid_rows_floors_a_zero_total_telmex_iu_row_to_one_cent(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type' => ScholarshipType::TELMEX_IU->value,
            'final_amount'              => 0.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertSame('0.01', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function paid_rows_never_floors_a_negative_total(): void
    {
        // Unreachable via any validated write path, but locks the floor's
        // exact-zero predicate (design D3) at the paidRows() layer too:
        // never `<= 0`.
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
            'final_amount'                 => -10.00,
            'amount_pending_from_previous' => 0,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertSame('-10.00', $rows[0]['total_to_pay']);
    }

    /** @test */
    public function summary_includes_a_floored_iu_row_alongside_normal_rows(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'final_amount'              => 0.00,
        ]);
        $this->makeReadyRefrend();

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertSame('1000.01', $summary['total_amount']);
    }

    // ── Filter C: EGRESO_RETICULA candidacy exclusion (sdd/egresado-status-timing, design D5, risk R2) ──

    /**
     * THE regression gate (design risk R2, High severity, "the single
     * highest-value test in this change"): a bare
     * where('r.resolution_type', '!=', 'EGRESO_RETICULA') would evaluate
     * SQL NULL for every row whose resolution_type is NULL (the ordinary
     * unresolved-refrend case) and silently drop the ENTIRE normal payroll
     * from the Pagos table. This test asserts a normal NULL-resolution_type
     * row is NOT excluded.
     */
    /** @test */
    public function a_normal_row_with_null_resolution_type_is_not_excluded_by_the_egreso_reticula_filter(): void
    {
        $this->makeReadyRefrend([
            'resolution_type' => null,
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows, 'A normal row with resolution_type=NULL must NOT be dropped by Filter C (the R2 NULL-trap regression).');
    }

    /** @test */
    public function an_egreso_reticula_row_is_absent_entirely_from_candidates(): void
    {
        $this->makeReadyRefrend([
            'final_amount'     => 0.00,
            'resolution_type'  => \App\Models\ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA,
            'workflow_status'  => 'CLOSED',
            'locked_at'        => now(),
        ]);

        $rows = $this->rows();

        $this->assertCount(0, $rows, 'An EGRESO_RETICULA row must be absent entirely — not shown as $0.00 or floored to $0.01.');
    }

    /** @test */
    public function an_egreso_reticula_row_is_excluded_while_a_normal_row_in_the_same_batch_still_appears(): void
    {
        $this->makeReadyRefrend([
            'snapshot_name'    => 'Becario Normal',
            'resolution_type'  => null,
        ]);
        $this->makeReadyRefrend([
            'snapshot_name'    => 'Becario Egresado',
            'final_amount'     => 0.00,
            'resolution_type'  => \App\Models\ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA,
            'workflow_status'  => 'CLOSED',
            'locked_at'        => now(),
        ]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Becario Normal', $rows[0]['snapshot_name']);
    }

    /** @test */
    public function other_resolution_types_are_never_excluded_by_filter_c(): void
    {
        $this->makeReadyRefrend(['resolution_type' => 'BECA_MES']);
        $this->makeReadyRefrend(['resolution_type' => 'EGRESADO']);

        $rows = $this->rows();

        $this->assertCount(2, $rows, 'Filter C must only ever exclude resolution_type=EGRESO_RETICULA, never any other value.');
    }

    // ── Part 2 (sdd/pagos-batch-sede-totals, design D9-D12): rows() additive
    // type-partition fields + summary() 5 money totals ──────────────────────

    /** @test */
    public function rows_emits_the_three_new_type_partition_fields_without_altering_any_previously_existing_field(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'snapshot_monto_apoyo'      => 150.00,
        ]);

        $rows = $this->rows();

        // New fields — raw pass-through, numeric affinity under sqlite (same
        // assertEquals convention as snapshot_temporary_increase_amount above).
        $this->assertEquals(ScholarshipType::IU->value, $rows[0]['snapshot_scholarship_type']);
        $this->assertEquals(1000.00, $rows[0]['base_amount']);
        $this->assertEquals(150.00, $rows[0]['snapshot_monto_apoyo']);

        // Previously-existing fields must survive unchanged.
        $this->assertSame('1000.00', $rows[0]['total_to_pay']);
        $this->assertTrue($rows[0]['is_payable']);
        $this->assertSame([], $rows[0]['blocking_reasons']);
    }

    /** @test */
    public function rows_returns_null_for_snapshot_monto_apoyo_when_absent(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'snapshot_monto_apoyo'      => null,
        ]);

        $rows = $this->rows();

        $this->assertNull($rows[0]['snapshot_monto_apoyo']);
    }

    /** @test */
    public function paid_rows_never_exposes_base_amount_or_snapshot_monto_apoyo(): void
    {
        $batch = $this->makeBatch();
        $this->makePaidRefrendForBatch($batch, [
            'snapshot_monto_apoyo' => 150.00,
        ]);

        $rows = $this->service->paidRows($batch);

        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('base_amount', $rows[0]);
        $this->assertArrayNotHasKey('snapshot_monto_apoyo', $rows[0]);
    }

    /** @test */
    public function summary_computes_beca_and_apoyo_totals_for_an_iu_only_batch(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'base_amount'               => 1000.00,
            'snapshot_monto_apoyo'      => 150.00,
            'final_amount'              => 1150.00,
        ]);
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'base_amount'               => 500.00,
            'snapshot_monto_apoyo'      => 100.00,
            'final_amount'              => 600.00,
        ]);

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertSame('1500.00', $summary['beca_amount']);
        $this->assertSame('250.00', $summary['apoyo_amount']);
        $this->assertSame('0.00', $summary['pago_iu_amount']);
        $this->assertSame('1750.00', $summary['total_amount'], 'total_amount must stay unchanged by this addition.');
        $this->assertSame('0.00', $summary['difference_amount'], 'No discounts or increases active — card 5 nets to zero.');
    }

    /** @test */
    public function summary_computes_pago_iu_amount_independently_from_beca_and_apoyo_in_a_mixed_batch(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'base_amount'               => 1000.00,
            'snapshot_monto_apoyo'      => 150.00,
            'final_amount'              => 1150.00,
        ]);
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::TELMEX_IU->value,
            'base_amount'               => 800.00,
            // Telmex-covered bookkeeping money — must NOT leak into card 2
            // (design D11's correctness-gate rationale).
            'snapshot_monto_apoyo'      => 200.00,
            'final_amount'              => 800.00,
        ]);

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertSame('1000.00', $summary['beca_amount']);
        $this->assertSame('150.00', $summary['apoyo_amount']);
        $this->assertSame('800.00', $summary['pago_iu_amount']);
        $this->assertSame('1950.00', $summary['total_amount']);
    }

    /** @test */
    public function summary_treats_null_snapshot_monto_apoyo_as_zero_in_apoyo_amount(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'base_amount'               => 1000.00,
            'snapshot_monto_apoyo'      => null,
            'final_amount'              => 1000.00,
        ]);

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertSame('0.00', $summary['apoyo_amount']);
    }

    /** @test */
    public function summary_difference_amount_is_negative_when_active_increases_or_discounts_dominate(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'base_amount'               => 1000.00,
            'snapshot_monto_apoyo'      => null,
            'final_amount'              => 2000.00,
        ]);

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertSame('-1000.00', $summary['difference_amount']);
    }

    /** @test */
    public function egreso_reticula_refrend_is_absent_from_all_five_summary_totals(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'base_amount'               => 1000.00,
            'snapshot_monto_apoyo'      => 150.00,
            'final_amount'              => 1150.00,
        ]);
        $this->makeReadyRefrend([
            'final_amount'     => 0.00,
            'resolution_type'  => \App\Models\ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA,
            'workflow_status'  => 'CLOSED',
            'locked_at'        => now(),
        ]);

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertSame('1000.00', $summary['beca_amount']);
        $this->assertSame('150.00', $summary['apoyo_amount']);
        $this->assertSame('0.00', $summary['pago_iu_amount']);
        $this->assertSame('1150.00', $summary['total_amount']);
        $this->assertSame('0.00', $summary['difference_amount']);
    }

    /**
     * Lock-in regression test (spec "Pure-TELMEX non-representation in
     * cards 1-3 is an accepted non-requirement") — NOT a fix. A pure TELMEX
     * becario has base_amount=0.00 by the type-partition formula and is
     * excluded from card 2's IU-only filter, so an active temporary
     * increase that makes it a batch candidate (Filter A) surfaces ONLY
     * inside card 4/card 5 — cards 1-3 stay unaffected.
     */
    /** @test */
    public function pure_telmex_with_an_active_increase_affects_only_card_four_and_cards_one_through_three_remain_unaffected(): void
    {
        $this->makeReadyRefrend([
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
            'base_amount'               => 1000.00,
            'snapshot_monto_apoyo'      => 150.00,
            'final_amount'              => 1150.00,
        ]);
        $this->makeReadyRefrend([
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'base_amount'                        => 0.00,
            'snapshot_monto_apoyo'               => null,
            'snapshot_temporary_increase_amount' => 500.00,
            'final_amount'                       => 500.00,
        ]);

        $rows    = $this->rows();
        $summary = $this->service->summary($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('1000.00', $summary['beca_amount'], 'The pure TELMEX increase must not leak into card 1.');
        $this->assertSame('150.00', $summary['apoyo_amount'], 'The pure TELMEX increase must not leak into card 2.');
        $this->assertSame('0.00', $summary['pago_iu_amount'], 'A pure TELMEX row is never TELMEX_IU — card 3 stays unaffected.');
        $this->assertSame('1650.00', $summary['total_amount'], 'Card 4 includes the TELMEX increase amount.');
        $this->assertSame('-500.00', $summary['difference_amount'], "The TELMEX becario's contribution surfaces only inside card 5's net difference.");
    }
}
