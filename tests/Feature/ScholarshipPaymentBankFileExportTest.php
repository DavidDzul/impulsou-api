<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\BankPaymentFileName;
use App\Services\Scholarship\BankPaymentFileSerializer;
use App\Services\Scholarship\PaymentBatchService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Covers PR4 (sdd/becario-payment-bank-file-export, Phase 4): the two
 * export HTTP endpoints — GET .../batches/{batch}/export/summary
 * (pre-flight JSON) and GET .../batches/{batch}/export (the file stream).
 * Both call the SAME PaymentBatchService::paidRows() +
 * BankDataValidator gate (design D4's invariant), so this suite asserts
 * that invariant directly instead of testing each endpoint as unrelated
 * surfaces.
 *
 * Fixture conventions mirror ScholarshipPaymentControllerTest's
 * makeReadyRefrend + RoleSeeder/ROOT_ADMINISTRATION setup. A dedicated
 * makeLegacyPaidBatch() helper bypasses process()/BulkPayAction entirely
 * (direct ::create()/->update() calls) to simulate a batch paid BEFORE
 * PaymentReadinessEvaluator started enforcing BankDataValidator (PR3) —
 * the exact scenario export-time validation exists to protect, per design's
 * "Legacy paid rows unaffected" scenario. A malformed row can no longer
 * reach a real paid batch through process() once PR3 landed, since the
 * readiness gate now blocks it before payment.
 */
class ScholarshipPaymentBankFileExportTest extends TestCase
{
    use RefreshDatabase;

    private const GENERATION_ID = 1;
    private const CAMPUS        = 'MERIDA';
    private const YEAR          = 2026;
    private const MONTH         = 5;

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    /**
     * Creates a becario user + refrend inside the default batch key, fully
     * ready (LISTO_PARA_PAGO, unlocked, enrollment, well-formed bank data)
     * by default so overrides can flip one thing at a time. Pass
     * `$paymentDataOverrides = null` to skip creating a ScholarshipPaymentData
     * row entirely.
     */
    private function makeReadyRefrend(
        array $refrendOverrides = [],
        array $userOverrides = [],
        ?array $paymentDataOverrides = []
    ): ScholarshipRefrend {
        $user = User::factory()->create(array_merge([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => self::CAMPUS,
            'active'     => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ], $userOverrides));

        if ($paymentDataOverrides !== null) {
            ScholarshipPaymentData::create(array_merge([
                'user_id'        => $user->id,
                'bank_name'      => 'BBVA',
                'account_number' => '0123456789',
                'curp'           => 'CURP010101HDFXXX01',
                'rfc'            => 'PEPJ800101ABC',
            ], $paymentDataOverrides));
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
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $refrendOverrides));
    }

    private function processPayload(array $overrides = []): array
    {
        return array_merge([
            'campus'       => self::CAMPUS,
            'period_year'  => self::YEAR,
            'period_month' => self::MONTH,
        ], $overrides);
    }

    /** Pays $count fresh, fully-ready refrends through the REAL process() endpoint. */
    private function payBatch(int $count): ScholarshipPaymentBatch
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeReadyRefrend([], ['enrollment' => 'MAT-' . random_int(100000, 999999)]);
        }

        $expectedTotal = number_format($count * 1000.00, 2, '.', '');

        $response = $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => $count, 'expected_total' => $expectedTotal])
        );

        $response->assertStatus(200);

        return ScholarshipPaymentBatch::findOrFail($response->json('data.batch_id'));
    }

    /**
     * Simulates a batch paid BEFORE PaymentReadinessEvaluator enforced
     * BankDataValidator (PR3) — bypasses process()/BulkPayAction entirely so
     * a malformed row can actually reach a PAID state, exactly the scenario
     * export-time validation exists to protect (design "Legacy paid rows
     * unaffected").
     *
     * @param array<int, array{refrendOverrides?: array, userOverrides?: array, paymentDataOverrides?: ?array}> $refrendSpecs
     */
    private function makeLegacyPaidBatch(array $refrendSpecs): ScholarshipPaymentBatch
    {
        $batch = ScholarshipPaymentBatch::create([
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            // Deliberately stale/irrelevant — paidRows() must never read
            // these columns (design's central correctness constraint).
            'refrend_count'   => 999,
            'total_amount'    => '999999.99',
            'processed_by_id' => $this->rootAdmin->id,
            'processed_at'    => now(),
        ]);

        foreach ($refrendSpecs as $spec) {
            $refrend = $this->makeReadyRefrend(
                $spec['refrendOverrides'] ?? [],
                $spec['userOverrides'] ?? [],
                array_key_exists('paymentDataOverrides', $spec) ? $spec['paymentDataOverrides'] : []
            );

            $refrend->update([
                'status'           => RefrendStatus::PAID->value,
                'workflow_status'  => 'CLOSED',
                'locked_at'        => now(),
                'locked_by_id'     => $this->rootAdmin->id,
                'payment_batch_id' => $batch->id,
            ]);
        }

        return $batch;
    }

    private function summaryUrl(int $batchId): string
    {
        return "/api/admin/scholarship-payments/batches/{$batchId}/export/summary";
    }

    private function exportUrl(int $batchId): string
    {
        return "/api/admin/scholarship-payments/batches/{$batchId}/export";
    }

    // ── Permission gate (403) ──────────────────────────────────────────────

    /** @test */
    public function export_summary_returns_403_for_a_user_with_zero_permissions(): void
    {
        $batch      = $this->payBatch(1);
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $response = $this->actingAs($noPermUser)->getJson($this->summaryUrl($batch->id));

        $response->assertStatus(403);
    }

    /** @test */
    public function export_returns_403_for_a_user_with_zero_permissions(): void
    {
        $batch      = $this->payBatch(1);
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $response = $this->actingAs($noPermUser)->getJson($this->exportUrl($batch->id));

        $response->assertStatus(403);
    }

    /** @test */
    public function export_summary_returns_403_for_a_caller_with_read_and_process_but_not_export(): void
    {
        $batch           = $this->payBatch(1);
        $readProcessUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $readProcessUser->givePermissionTo('ADM_READ_PAYMENTS', 'ADM_PROCESS_PAYMENTS');

        $response = $this->actingAs($readProcessUser)->getJson($this->summaryUrl($batch->id));

        $response->assertStatus(403);
    }

    /** @test */
    public function export_returns_403_for_a_caller_with_read_and_process_but_not_export(): void
    {
        $batch           = $this->payBatch(1);
        $readProcessUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $readProcessUser->givePermissionTo('ADM_READ_PAYMENTS', 'ADM_PROCESS_PAYMENTS');

        $response = $this->actingAs($readProcessUser)->getJson($this->exportUrl($batch->id));

        $response->assertStatus(403);
    }

    // ── Happy path: byte-identical file + matching summary ─────────────────

    /** @test */
    public function export_streams_a_byte_identical_file_to_the_independently_built_serializer_output(): void
    {
        $batch = $this->payBatch(3);

        $response = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $rows     = app(PaymentBatchService::class)->paidRows($batch->fresh());
        $expected = (new BankPaymentFileSerializer())->serialize($rows);

        $this->assertSame($expected, $response->streamedContent());

        $expectedFilename = BankPaymentFileName::forBatch($batch->fresh());
        $response->assertHeader('Content-Disposition', 'attachment; filename=' . $expectedFilename);
    }

    /** @test */
    public function export_summary_matches_exactly_what_the_file_endpoint_would_produce(): void
    {
        $batch = $this->payBatch(2);

        $summaryResponse = $this->actingAs($this->rootAdmin)->getJson($this->summaryUrl($batch->id));
        $summaryResponse->assertStatus(200);

        $fileResponse = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));
        $fileResponse->assertStatus(200);

        $body  = $fileResponse->streamedContent();
        $lines = array_values(array_filter(explode("\r\n", $body)));

        $this->assertSame(count($lines), $summaryResponse->json('data.count'));

        // Importe occupies cols 48-62 (0-indexed offset 47, width 15),
        // pesos x 100 per design's ground-truth record layout.
        $sumFromFile = array_reduce($lines, function (float $carry, string $line) {
            $centavos = (int) substr($line, 47, 15);

            return $carry + ($centavos / 100);
        }, 0.0);

        $this->assertSame(number_format($sumFromFile, 2, '.', ''), $summaryResponse->json('data.total_amount'));
        $this->assertSame(BankPaymentFileName::forBatch($batch->fresh()), $summaryResponse->json('data.filename'));
    }

    // ── All-or-nothing 422 on malformed row (both endpoints, same code path) ──

    /** @test */
    public function export_returns_422_naming_the_offending_becario_and_streams_zero_file_bytes(): void
    {
        $batch = $this->makeLegacyPaidBatch([
            ['refrendOverrides' => ['snapshot_name' => 'Becario Bueno']],
            [
                'refrendOverrides'      => ['snapshot_name' => 'Becario Malo'],
                'paymentDataOverrides'  => ['account_number' => 'ABC123'],
            ],
        ]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->exportUrl($batch->id));

        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/json');

        $invalidRows = $response->json('data.invalid_rows');
        $this->assertCount(1, $invalidRows);
        $this->assertSame('Becario Malo', $invalidRows[0]['snapshot_name']);
        $this->assertContains('INVALID_ACCOUNT_NUMBER', array_column($invalidRows[0]['reasons'], 'code'));

        // Zero file bytes leaked — the response is a JSON object, never the
        // start of a fixed-width record (which would begin with 9 digits).
        $this->assertIsArray($response->json());
        $this->assertNull($response->json('data.count'));
    }

    /** @test */
    public function export_summary_returns_the_identical_422_shape_as_the_file_endpoint(): void
    {
        $batch = $this->makeLegacyPaidBatch([
            [
                'refrendOverrides'     => ['snapshot_name' => 'Becario Malo'],
                'paymentDataOverrides' => ['account_number' => 'ABC123'],
            ],
        ]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->summaryUrl($batch->id));

        $response->assertStatus(422);
        $invalidRows = $response->json('data.invalid_rows');
        $this->assertCount(1, $invalidRows);
        $this->assertSame('Becario Malo', $invalidRows[0]['snapshot_name']);
        $this->assertContains('INVALID_ACCOUNT_NUMBER', array_column($invalidRows[0]['reasons'], 'code'));
    }

    /** @test */
    public function export_with_a_malformed_row_leaves_the_database_completely_unchanged(): void
    {
        $batch = $this->makeLegacyPaidBatch([
            [
                'refrendOverrides'     => ['snapshot_name' => 'Becario Malo'],
                'paymentDataOverrides' => ['account_number' => 'ABC123'],
            ],
        ]);
        $refrendBefore = $batch->refrends()->first();

        $this->actingAs($this->rootAdmin)->getJson($this->exportUrl($batch->id))->assertStatus(422);

        $refrendBefore->refresh();
        $this->assertSame('CLOSED', $refrendBefore->workflow_status);
        $this->assertSame($batch->id, $refrendBefore->payment_batch_id);
        $this->assertSame(1, ScholarshipPaymentBatch::count());
    }

    // ── "Not actually paid" rejection ───────────────────────────────────────

    /** @test */
    public function export_returns_422_for_a_batch_with_zero_paid_refrends(): void
    {
        $batch = ScholarshipPaymentBatch::create([
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 0,
            'total_amount'    => '0.00',
            'processed_by_id' => $this->rootAdmin->id,
            'processed_at'    => now(),
        ]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->exportUrl($batch->id));

        $response->assertStatus(422);
    }

    /** @test */
    public function export_summary_returns_422_for_a_batch_with_zero_paid_refrends(): void
    {
        $batch = ScholarshipPaymentBatch::create([
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 0,
            'total_amount'    => '0.00',
            'processed_by_id' => $this->rootAdmin->id,
            'processed_at'    => now(),
        ]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->summaryUrl($batch->id));

        $response->assertStatus(422);
    }

    /**
     * This project's global App\Exceptions\Handler converts EVERY
     * ModelNotFoundException (route-model-binding miss, implicit or
     * otherwise) to 400 with a fixed {"res": false, "error": "..."} shape —
     * not Laravel's default 404. That is the established, app-wide
     * convention (untouched here, not something this PR should override for
     * just these two routes), so "rejected appropriately" for an unknown
     * batch id means 400, not 404.
     */
    /** @test */
    public function export_returns_400_for_a_nonexistent_batch(): void
    {
        $response = $this->actingAs($this->rootAdmin)->getJson($this->exportUrl(999999));

        $response->assertStatus(400);
    }

    /** @test */
    public function export_summary_returns_400_for_a_nonexistent_batch(): void
    {
        $response = $this->actingAs($this->rootAdmin)->getJson($this->summaryUrl(999999));

        $response->assertStatus(400);
    }

    // ── Filter B: TELMEX $0 rows silently excluded (sdd/scholarship-telmex-iu-split, design D4) ──

    /** @test */
    public function export_excludes_a_zero_total_telmex_row_but_succeeds_with_200_for_the_rest(): void
    {
        // The proposal's #1 risk: a batch with one $0 TELMEX row (increase
        // fully discounted) must NOT 422 the whole export — it must succeed
        // with that row silently absent from the file.
        $batch = $this->makeLegacyPaidBatch([
            ['refrendOverrides' => ['snapshot_name' => 'Becario IU Normal']],
            [
                'refrendOverrides' => [
                    'snapshot_name'               => 'Becario Telmex Cero',
                    'snapshot_scholarship_type'   => ScholarshipType::TELMEX->value,
                    'final_amount'                => 0.00,
                    'amount_pending_from_previous' => 0,
                ],
            ],
        ]);

        $response = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));

        $response->assertStatus(200);

        $body  = $response->streamedContent();
        $lines = array_values(array_filter(explode("\r\n", $body)));
        $this->assertCount(1, $lines, 'Only the IU row should be present in the file — the $0 TELMEX row must be silently excluded.');
    }

    /** @test */
    public function export_summary_excludes_the_same_zero_total_telmex_row_and_matches_export_exactly(): void
    {
        $batch = $this->makeLegacyPaidBatch([
            ['refrendOverrides' => ['snapshot_name' => 'Becario IU Normal']],
            [
                'refrendOverrides' => [
                    'snapshot_name'               => 'Becario Telmex Cero',
                    'snapshot_scholarship_type'   => ScholarshipType::TELMEX->value,
                    'final_amount'                => 0.00,
                    'amount_pending_from_previous' => 0,
                ],
            ],
        ]);

        $summaryResponse = $this->actingAs($this->rootAdmin)->getJson($this->summaryUrl($batch->id));
        $summaryResponse->assertStatus(200);

        $fileResponse = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));
        $fileResponse->assertStatus(200);

        $lines = array_values(array_filter(explode("\r\n", $fileResponse->streamedContent())));

        $this->assertSame(1, $summaryResponse->json('data.count'));
        $this->assertSame(count($lines), $summaryResponse->json('data.count'));
        $this->assertSame('1000.00', $summaryResponse->json('data.total_amount'));
    }

    /** @test */
    public function telmex_row_with_pending_from_previous_still_exports_despite_zero_final_amount(): void
    {
        // Guards the final_amount-vs-total_to_pay mistake: this row's
        // final_amount is 0 this period, but total_to_pay > 0 via
        // amount_pending_from_previous — Filter B reads total_to_pay, so it
        // must NOT exclude this row.
        $batch = $this->makeLegacyPaidBatch([
            [
                'refrendOverrides' => [
                    'snapshot_name'                 => 'Becario Telmex Pendiente',
                    'snapshot_scholarship_type'     => ScholarshipType::TELMEX->value,
                    'final_amount'                  => 0.00,
                    'amount_pending_from_previous'  => 300.00,
                ],
            ],
        ]);

        $response = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));

        $response->assertStatus(200);
        $lines = array_values(array_filter(explode("\r\n", $response->streamedContent())));
        $this->assertCount(1, $lines, 'A TELMEX row with a positive total_to_pay (via amount_pending_from_previous) must still export.');
    }

    /** @test */
    public function iu_zero_total_row_now_floors_to_one_cent_and_exports_successfully(): void
    {
        // sdd/bank-file-minimum-deposit: an IU row at total_to_pay == 0.00
        // now floors to 0.01 and exports successfully instead of 422-ing
        // the whole batch (previous behavior, before this change).
        $batch = $this->makeLegacyPaidBatch([
            [
                'refrendOverrides' => [
                    'snapshot_name'                => 'Becario IU Cero',
                    'snapshot_scholarship_type'    => ScholarshipType::IU->value,
                    'final_amount'                 => 0.00,
                    'amount_pending_from_previous' => 0,
                ],
            ],
        ]);

        $response = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));

        $response->assertStatus(200);

        $body  = $response->streamedContent();
        $lines = array_values(array_filter(explode("\r\n", $body)));
        $this->assertCount(1, $lines);
        // Consecutivo (cols 1-9, zero-padded) confirms this is the single
        // row's own record, position 1.
        $this->assertStringStartsWith('000000001', $lines[0]);

        // Importe occupies cols 48-62 (0-indexed offset 47, width 15) —
        // centavos, per BankPaymentFileSerializer::importe(). 0.01 -> "1".
        $centavos = (int) substr($lines[0], 47, 15);
        $this->assertSame(1, $centavos);
    }

    /** @test */
    public function telmex_iu_zero_total_row_now_floors_to_one_cent_and_exports_successfully(): void
    {
        $batch = $this->makeLegacyPaidBatch([
            [
                'refrendOverrides' => [
                    'snapshot_name'                => 'Becario Telmex IU Cero',
                    'snapshot_scholarship_type'    => ScholarshipType::TELMEX_IU->value,
                    'final_amount'                 => 0.00,
                    'amount_pending_from_previous' => 0,
                ],
            ],
        ]);

        $response = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));

        $response->assertStatus(200);

        $body  = $response->streamedContent();
        $lines = array_values(array_filter(explode("\r\n", $body)));
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('000000001', $lines[0]);

        $centavos = (int) substr($lines[0], 47, 15);
        $this->assertSame(1, $centavos);
    }

    /** @test */
    public function negative_total_row_still_blocks_the_whole_export_with_nothing_to_pay_and_is_never_floored(): void
    {
        // The floor's exact-zero predicate (design D3) must never catch a
        // negative total — that stays a hard failure, unreachable via any
        // validated write path but must fail loudly if it ever occurs.
        $batch = $this->makeLegacyPaidBatch([
            [
                'refrendOverrides' => [
                    'snapshot_name'                => 'Becario IU Negativo',
                    'snapshot_scholarship_type'    => ScholarshipType::IU->value,
                    'final_amount'                 => -10.00,
                    'amount_pending_from_previous' => 0,
                ],
            ],
        ]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->exportUrl($batch->id));

        $response->assertStatus(422);
        $this->assertContains(
            'NOTHING_TO_PAY',
            array_column($response->json('data.invalid_rows.0.reasons'), 'code')
        );
    }

    /** @test */
    public function telmex_iu_with_active_increase_covering_the_discount_is_unaffected_by_the_floor(): void
    {
        // Net total_to_pay > 0 after the discount — the floor never fires.
        $batch = $this->makeLegacyPaidBatch([
            [
                'refrendOverrides' => [
                    'snapshot_name'                       => 'Becario Telmex IU Con Incremento',
                    'snapshot_scholarship_type'           => ScholarshipType::TELMEX_IU->value,
                    'snapshot_temporary_increase_amount'  => 500.00,
                    'final_amount'                        => 500.00,
                    'amount_pending_from_previous'        => 0,
                ],
            ],
        ]);

        $response = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));

        $response->assertStatus(200);
        $lines = array_values(array_filter(explode("\r\n", $response->streamedContent())));
        $this->assertCount(1, $lines);

        $centavos = (int) substr($lines[0], 47, 15);
        $this->assertSame(50000, $centavos, 'The floor must not alter a positive total_to_pay.');
    }

    /** @test */
    public function pure_telmex_with_no_active_increase_stays_absent_from_everything_unaffected_by_the_floor(): void
    {
        // Pre-existing Filter A/B behavior (sdd/scholarship-telmex-iu-split)
        // — confirms no accidental regression from this change.
        $batch = $this->makeLegacyPaidBatch([
            ['refrendOverrides' => ['snapshot_name' => 'Becario IU Normal']],
            [
                'refrendOverrides' => [
                    'snapshot_name'                       => 'Becario Telmex Sin Incremento',
                    'snapshot_scholarship_type'           => ScholarshipType::TELMEX->value,
                    'snapshot_temporary_increase_amount'  => null,
                    'final_amount'                        => 0.00,
                    'amount_pending_from_previous'        => 0,
                ],
            ],
        ]);

        $summaryResponse = $this->actingAs($this->rootAdmin)->getJson($this->summaryUrl($batch->id));
        $summaryResponse->assertStatus(200);
        $this->assertSame(1, $summaryResponse->json('data.count'));

        $response = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));
        $response->assertStatus(200);
        $lines = array_values(array_filter(explode("\r\n", $response->streamedContent())));
        $this->assertCount(1, $lines, 'Only the normal IU row should be present — the pure TELMEX row without an increase stays absent, unfloored.');
    }

    /**
     * Mixed batch reconciles across all layers (spec "Mixed batch
     * reconciles across all layers" / "Cross-layer amount consistency for
     * floored rows"): normal row + $0.00 IU row (floored) + excluded pure
     * TELMEX row (fully discounted by an active increase) -> Pagos-table
     * equivalent (rows()), summary card (summary()), exportSummary(), and
     * the bank file all agree.
     */
    /** @test */
    public function mixed_batch_reconciles_the_floored_row_and_the_excluded_row_across_every_layer(): void
    {
        $batch = $this->makeLegacyPaidBatch([
            [
                'refrendOverrides' => [
                    'snapshot_name'                => 'Becario Normal',
                    'snapshot_scholarship_type'    => ScholarshipType::IU->value,
                    'final_amount'                 => 1000.00,
                ],
            ],
            [
                'refrendOverrides' => [
                    'snapshot_name'                => 'Becario IU Cero',
                    'snapshot_scholarship_type'    => ScholarshipType::IU->value,
                    'final_amount'                 => 0.00,
                    'amount_pending_from_previous' => 0,
                ],
            ],
            [
                'refrendOverrides' => [
                    'snapshot_name'                       => 'Becario Telmex Excluido',
                    'snapshot_scholarship_type'           => ScholarshipType::TELMEX->value,
                    'snapshot_temporary_increase_amount'  => 500.00,
                    'final_amount'                        => 0.00,
                    'amount_pending_from_previous'        => 0,
                ],
            ],
        ]);

        $rows = app(PaymentBatchService::class)->paidRows($batch->fresh());
        $this->assertCount(2, $rows, 'The excluded TELMEX row must not reach paidRows() at all.');

        $summaryResponse = $this->actingAs($this->rootAdmin)->getJson($this->summaryUrl($batch->id));
        $summaryResponse->assertStatus(200);
        $this->assertSame(2, $summaryResponse->json('data.count'));
        $this->assertSame('1000.01', $summaryResponse->json('data.total_amount'));

        $fileResponse = $this->actingAs($this->rootAdmin)->get($this->exportUrl($batch->id));
        $fileResponse->assertStatus(200);
        $lines = array_values(array_filter(explode("\r\n", $fileResponse->streamedContent())));
        $this->assertCount(2, $lines, 'The excluded TELMEX row must be absent from the file, the normal + floored IU rows must both be present.');

        $sumFromFile = array_reduce($lines, function (float $carry, string $line) {
            $centavos = (int) substr($line, 47, 15);

            return $carry + ($centavos / 100);
        }, 0.0);
        $this->assertSame('1000.01', number_format($sumFromFile, 2, '.', ''));
        $this->assertSame($summaryResponse->json('data.total_amount'), number_format($sumFromFile, 2, '.', ''), 'exportSummary() and the file must agree exactly, including the floored cent.');
    }

    // ── Route ordering (static segments before {refrend} param) ────────────

    /** @test */
    public function export_routes_are_registered_before_the_refrend_document_route(): void
    {
        $uris = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin/scholarship-payments'))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $summaryIndex  = array_search('api/admin/scholarship-payments/batches/{batch}/export/summary', $uris, true);
        $exportIndex   = array_search('api/admin/scholarship-payments/batches/{batch}/export', $uris, true);
        $documentIndex = array_search('api/admin/scholarship-payments/{refrend}/document', $uris, true);

        $this->assertNotFalse($summaryIndex, 'export/summary route must be registered');
        $this->assertNotFalse($exportIndex, 'export route must be registered');
        $this->assertNotFalse($documentIndex, '{refrend}/document route must be registered');

        $this->assertLessThan($documentIndex, $summaryIndex);
        $this->assertLessThan($documentIndex, $exportIndex);
    }
}
