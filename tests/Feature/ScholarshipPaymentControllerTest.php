<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;
use App\Services\Scholarship\RefrendRetentionBreakdown;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers PR4 (sdd/becario-payment-file-generation, Phase 4): the HTTP
 * surface that wires PaymentReadinessEvaluator + PaymentBatchService (PR2)
 * and BulkPayAction (PR3) together — GET index (review list), GET document
 * (single-becario payment document), POST process (the all-or-nothing money
 * gate, design D2).
 *
 * Fixture conventions mirror PaymentBatchServiceTest::makeReadyRefrend (same
 * default batch key) and BulkPayEndpointTest's permission setup
 * (RoleSeeder + ROOT_ADMINISTRATION for the happy-path caller).
 */
class ScholarshipPaymentControllerTest extends TestCase
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
     * ready (LISTO_PARA_PAGO, unlocked, enrollment, bank data) by default so
     * a single override can flip it into a specific blocking case.
     */
    private function makeReadyRefrend(array $refrendOverrides = [], array $userOverrides = [], bool $withPaymentData = true): ScholarshipRefrend
    {
        $user = User::factory()->create(array_merge([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => self::CAMPUS,
            'active'     => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
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

    /**
     * Builds an additional refrend for the SAME becario, e.g. a past-period
     * origin refrend for a withholding — no separate user/bank data, unlike
     * makeReadyRefrend() (sdd/withholding-detail-display PR2 retentions
     * fixtures, mirrors RefrendRetentionBreakdownTest::makeRefrend).
     */
    private function makeRefrendForUser(int $userId, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
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
        ], $overrides));
    }

    private function makeWithholding(int $userId, int $originRefrendId, array $overrides = []): ScholarshipWithholding
    {
        return ScholarshipWithholding::create(array_merge([
            'user_id'           => $userId,
            'origin_refrend_id' => $originRefrendId,
            'period_year'       => self::YEAR,
            'period_month'      => self::MONTH,
            'withheld_amount'   => '300.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
            'cause'             => 'BAJO_PROMEDIO',
            'created_by_id'     => $this->rootAdmin->id,
        ], $overrides));
    }

    private function makeWithholdingPayment(int $withholdingId, int $appliedRefrendId, array $overrides = []): ScholarshipWithholdingPayment
    {
        return ScholarshipWithholdingPayment::create(array_merge([
            'withholding_id'     => $withholdingId,
            'applied_refrend_id' => $appliedRefrendId,
            'amount'             => '100.00',
            'created_by_id'      => $this->rootAdmin->id,
            'is_voided'          => false,
        ], $overrides));
    }

    private function indexUrl(): string
    {
        return '/api/admin/scholarship-payments?' . http_build_query([
            'generation_id' => self::GENERATION_ID,
            'campus'        => self::CAMPUS,
            'period_year'   => self::YEAR,
            'period_month'  => self::MONTH,
        ]);
    }

    private function processPayload(array $overrides = []): array
    {
        return array_merge([
            'generation_id' => self::GENERATION_ID,
            'campus'        => self::CAMPUS,
            'period_year'   => self::YEAR,
            'period_month'  => self::MONTH,
        ], $overrides);
    }

    // ── Permission gate (403) ──────────────────────────────────────────────

    /** @test */
    public function index_returns_403_without_adm_read_payments_permission(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $response = $this->actingAs($noPermUser)->getJson($this->indexUrl());

        $response->assertStatus(403);
    }

    /** @test */
    public function document_returns_403_without_adm_read_payments_permission(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $refrend    = $this->makeReadyRefrend();

        $response = $this->actingAs($noPermUser)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(403);
    }

    /** @test */
    public function process_returns_403_without_adm_process_payments_permission(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $refrend    = $this->makeReadyRefrend();

        $response = $this->actingAs($noPermUser)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 1, 'expected_total' => '1000.00'])
        );

        $response->assertStatus(403);
        $refrend->refresh();
        $this->assertNull($refrend->locked_at, 'Nothing should be mutated when the permission check fails.');
    }

    /** @test */
    public function process_returns_403_for_a_caller_with_only_adm_read_payments(): void
    {
        $readOnlyUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $readOnlyUser->givePermissionTo('ADM_READ_PAYMENTS');
        $refrend = $this->makeReadyRefrend();

        $response = $this->actingAs($readOnlyUser)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 1, 'expected_total' => '1000.00'])
        );

        $response->assertStatus(403);
        $refrend->refresh();
        $this->assertNull($refrend->locked_at);
    }

    // ── index() ──────────────────────────────────────────────────────────────

    /** @test */
    public function index_returns_correct_row_and_summary_shape_for_a_mixed_readiness_batch(): void
    {
        $this->makeReadyRefrend();
        $this->makeReadyRefrend();
        $this->makeReadyRefrend([], ['enrollment' => null]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data.rows');

        $summary = $response->json('data.summary');
        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['ready']);
        $this->assertSame(1, $summary['blocking']);
        $this->assertSame('2000.00', $summary['total_amount']);

        $blockedRow = collect($response->json('data.rows'))->firstWhere('is_payable', false);
        $this->assertNotNull($blockedRow);
        $this->assertContains('MISSING_ENROLLMENT', array_column($blockedRow['blocking_reasons'], 'code'));

        // has_incident / has_pending_from_previous ride along automatically:
        // index() passes PaymentBatchService::rows() straight through with no
        // reshaping/whitelisting, so a ready row with neither condition must
        // show both flags false here (the true-case is exercised end-to-end
        // below in index_surfaces_has_incident_and_has_pending_from_previous_flags).
        foreach ($response->json('data.rows') as $row) {
            $this->assertArrayHasKey('has_incident', $row);
            $this->assertArrayHasKey('has_pending_from_previous', $row);
            $this->assertFalse($row['has_incident']);
            $this->assertFalse($row['has_pending_from_previous']);
        }
    }

    // ── index(): batch block (PR3, design D3 — export reachable after reload) ──

    /** @test */
    public function index_returns_a_null_batch_block_when_the_period_has_not_been_paid(): void
    {
        $this->makeReadyRefrend();

        $response = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());

        $response->assertStatus(200);
        $this->assertNull($response->json('data.batch.batch_id'));
        $this->assertFalse($response->json('data.batch.is_paid'));
    }

    /** @test */
    public function index_returns_the_batch_id_and_is_paid_true_after_the_period_was_paid(): void
    {
        $this->makeReadyRefrend();

        $processResponse = $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 1, 'expected_total' => '1000.00'])
        );
        $processResponse->assertStatus(200);
        $batchId = $processResponse->json('data.batch_id');

        // Simulates the realistic "pay today, download later" workflow —
        // a fresh index() call after the SPA's transient batchId is gone.
        $response = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());

        $response->assertStatus(200);
        $this->assertSame($batchId, $response->json('data.batch.batch_id'));
        $this->assertTrue($response->json('data.batch.is_paid'));
    }

    /** @test */
    public function index_batch_derivation_adds_zero_extra_queries(): void
    {
        // Design D3's explicit claim: batch_id/is_paid is derived from a
        // column rows() already SELECTs — no new query. Compare the query
        // count of an unpaid index() call against a paid one; they must be
        // identical, proving the batch block is not backed by a new query.
        // A throwaway warm-up call primes Spatie's permission cache first,
        // so that one-time cache-fill cost doesn't pollute the comparison.
        $this->makeReadyRefrend();
        $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());

        \DB::enableQueryLog();
        $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());
        $unpaidQueryCount = count(\DB::getQueryLog());
        \DB::flushQueryLog();
        \DB::disableQueryLog();

        $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 1, 'expected_total' => '1000.00'])
        )->assertStatus(200);

        \DB::enableQueryLog();
        $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());
        $paidQueryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(
            $unpaidQueryCount,
            $paidQueryCount,
            'index() must derive the batch block from already-selected data — zero additional queries.'
        );
    }

    /** @test */
    public function index_surfaces_has_incident_and_has_pending_from_previous_flags(): void
    {
        $withIncident = $this->makeReadyRefrend();
        $withIncident->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'INASISTENCIA',
            'incident_date'     => '2026-05-10',
            'description'       => 'Faltó a clase sin justificación.',
            'is_resolved'       => false,
        ]);

        $withPending = $this->makeReadyRefrend([
            'amount_pending_from_previous' => 200.00,
        ]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->indexUrl());

        $response->assertStatus(200);
        $rows = collect($response->json('data.rows'))->keyBy('refrend_id');

        $this->assertTrue($rows[$withIncident->id]['has_incident']);
        $this->assertFalse($rows[$withIncident->id]['has_pending_from_previous']);

        $this->assertFalse($rows[$withPending->id]['has_incident']);
        $this->assertTrue($rows[$withPending->id]['has_pending_from_previous']);
    }

    // ── document() ───────────────────────────────────────────────────────────

    /** @test */
    public function document_returns_the_correct_fields_for_a_real_refrend(): void
    {
        $refrend = $this->makeReadyRefrend([
            'atencion_observations'   => 'Llegó tarde dos veces.',
            'pedagogia_observations'  => 'Bajo rendimiento en cálculo.',
            'resolution_notes'        => 'Aprobado tras revisión.',
            'carryover_months_count'  => 2,
            'carryover_months_detail' => '04/2026: 300.00; 03/2026: 150.00',
            'final_amount'            => 1000.00,
            'amount_pending_from_previous' => 450.00,
        ], ['enrollment' => 'MAT-123456']);

        $refrend->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'INASISTENCIA',
            'incident_date'     => '2026-05-10',
            'description'       => 'Faltó a clase sin justificación.',
            'is_resolved'       => false,
        ]);

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame($refrend->id, $data['refrend_id']);
        $this->assertSame('MAT-123456', $data['enrollment']);
        $this->assertSame('Test Becario', $data['snapshot_name']);

        $this->assertCount(1, $data['incidents']);
        $this->assertSame('Faltó a clase sin justificación.', $data['incidents'][0]['description']);
        $this->assertFalse($data['incidents'][0]['is_resolved']);

        $this->assertSame(2, $data['carryover_months_count']);
        $this->assertSame('04/2026: 300.00; 03/2026: 150.00', $data['carryover_months_detail']);
        $this->assertArrayNotHasKey('carryover_percentage', $data);

        $this->assertSame('Llegó tarde dos veces.', $data['atencion_observations']);
        $this->assertSame('Bajo rendimiento en cálculo.', $data['pedagogia_observations']);
        $this->assertSame('Aprobado tras revisión.', $data['resolution_notes']);

        $this->assertSame('1000.00', $data['amount_breakdown']['final_amount']);
        $this->assertSame('450.00', $data['amount_breakdown']['amount_pending_from_previous']);
        $this->assertSame('1450.00', $data['amount_breakdown']['total_to_pay']);
    }

    // ── document(): retentions (sdd/withholding-detail-display, PR2) ───────────

    /** @test */
    public function document_includes_retentions_ledger_applied_breakdown_matching_the_breakdown_service(): void
    {
        $becario = User::factory()->create([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => self::CAMPUS,
            'active'     => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ]);
        ScholarshipPaymentData::create([
            'user_id'        => $becario->id,
            'bank_name'      => 'BBVA',
            'account_number' => '0123456789',
            'curp'           => 'CURP010101HDFXXX01',
            'rfc'            => 'PEPJ800101ABC',
        ]);

        $origin      = $this->makeRefrendForUser($becario->id, ['period_month' => 1]);
        $withholding = $this->makeWithholding($becario->id, $origin->id, [
            'period_month' => 1, 'withheld_amount' => '300.00', 'cause' => 'BAJO_PROMEDIO',
        ]);
        $refrend = $this->makeRefrendForUser($becario->id, [
            'period_month'                 => self::MONTH,
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'amount_pending_from_previous' => 300.00,
            'final_amount'                 => 1300.00,
        ]);
        $this->makeWithholdingPayment($withholding->id, $refrend->id, ['amount' => '300.00']);
        $withholding->recomputePaidAmount();

        $expected = app(RefrendRetentionBreakdown::class)->forRefrend($refrend->fresh());

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayHasKey('retentions', $data);
        $this->assertSame($expected, $data['retentions']);
        $this->assertCount(1, $data['retentions']['ledger_applied']);
        $this->assertSame($withholding->id, $data['retentions']['ledger_applied'][0]['withholding_id']);
        $this->assertSame('300.00', $data['retentions']['ledger_applied'][0]['amount_applied_now']);
        $this->assertSame('300.00', $data['retentions']['ledger_applied_total']);
    }

    /** @test */
    public function document_includes_retentions_origin_withholding_when_active(): void
    {
        $refrend     = $this->makeReadyRefrend(['period_month' => 1]);
        $withholding = $this->makeWithholding($refrend->user_id, $refrend->id, [
            'status' => 'PENDING', 'withheld_amount' => '250.00', 'cause' => 'OTRO',
        ]);

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(200);
        $originWithholding = $response->json('data.retentions.origin_withholding');

        $this->assertNotNull($originWithholding);
        $this->assertSame($withholding->id, $originWithholding['withholding_id']);
        $this->assertSame('250.00', $originWithholding['withheld_amount']);
        $this->assertSame('PENDING', $originWithholding['status']);
    }

    /** @test */
    public function document_retentions_origin_withholding_is_null_when_the_originating_withholding_was_cancelled(): void
    {
        $refrend = $this->makeReadyRefrend([
            'resolution_type'     => 'DESCUENTO_DEFINITIVO',
            'discount_amount'     => '120.00',
            'discount_percentage' => '40.00',
            'resolution_cause'    => 'OTRO',
        ]);
        $this->makeWithholding($refrend->user_id, $refrend->id, ['status' => 'CANCELLED']);

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(200);
        $this->assertNull($response->json('data.retentions.origin_withholding'));
        $this->assertNotNull($response->json('data.retentions.definitive_discount'));
    }

    /** @test */
    public function document_includes_retentions_attendance_discounts(): void
    {
        $refrend = $this->makeReadyRefrend();
        ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => 'RETARDOS',
            'discount_percentage'    => '10.00',
            'description'            => 'Tres retardos en el mes.',
        ]);

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(200);
        $discounts = $response->json('data.retentions.attendance_discounts');

        $this->assertCount(1, $discounts);
        $this->assertSame('RETARDOS', $discounts[0]['discount_type']);
        $this->assertArrayNotHasKey('amount', $discounts[0]);
    }

    /** @test */
    public function document_includes_retentions_definitive_discount(): void
    {
        $refrend = $this->makeReadyRefrend([
            'resolution_type'     => 'DESCUENTO_DEFINITIVO',
            'discount_amount'     => '400.00',
            'discount_percentage' => '40.00',
            'resolution_cause'    => 'BAJO_PROMEDIO',
        ]);

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(200);
        $definitive = $response->json('data.retentions.definitive_discount');

        $this->assertNotNull($definitive);
        $this->assertSame('400.00', $definitive['discount_amount']);
        $this->assertSame('40.00', $definitive['discount_percentage']);
        $this->assertSame('BAJO_PROMEDIO', $definitive['resolution_cause']);
    }

    /** @test */
    public function document_retentions_are_all_empty_when_no_retention_activity_exists(): void
    {
        $refrend = $this->makeReadyRefrend();

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(200);
        $retentions = $response->json('data.retentions');

        $this->assertSame([], $retentions['ledger_applied']);
        $this->assertSame('0.00', $retentions['ledger_applied_total']);
        $this->assertNull($retentions['origin_withholding']);
        $this->assertSame([], $retentions['attendance_discounts']);
        $this->assertNull($retentions['definitive_discount']);
    }

    /** @test */
    public function document_retentions_are_visible_with_only_adm_read_payments_permission_no_new_permission_needed(): void
    {
        $readOnlyUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $readOnlyUser->givePermissionTo('ADM_READ_PAYMENTS');
        $refrend = $this->makeReadyRefrend();
        ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => 'RETARDOS',
            'discount_percentage'    => '10.00',
            'description'            => 'Un retardo.',
        ]);

        $response = $this->actingAs($readOnlyUser)
            ->getJson("/api/admin/scholarship-payments/{$refrend->id}/document");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.retentions.attendance_discounts'));
    }

    // ── process(): all-or-nothing gate (422) ────────────────────────────────

    /** @test */
    public function process_with_one_blocked_row_returns_422_and_mutates_nothing(): void
    {
        $ready   = $this->makeReadyRefrend();
        $blocked = $this->makeReadyRefrend([], ['enrollment' => null]);

        $response = $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 2, 'expected_total' => '1000.00'])
        );

        $response->assertStatus(422);
        $blockingRows = $response->json('data.blocking_rows');
        $this->assertCount(1, $blockingRows);
        $this->assertSame($blocked->id, $blockingRows[0]['refrend_id']);
        $this->assertContains('MISSING_ENROLLMENT', array_column($blockingRows[0]['blocking_reasons'], 'code'));

        $ready->refresh();
        $blocked->refresh();
        $this->assertNull($ready->locked_at, 'The all-or-nothing gate must reject the WHOLE batch, not just the blocked row.');
        $this->assertNull($ready->payment_batch_id);
        $this->assertNull($blocked->locked_at);
        $this->assertSame(0, ScholarshipPaymentBatch::count());
    }

    // ── process(): optimistic-concurrency gate (409) ────────────────────────

    /** @test */
    public function process_with_stale_expected_count_returns_409_and_writes_nothing(): void
    {
        $refrend = $this->makeReadyRefrend();

        // Client believed there were 2 records (e.g. before one was removed
        // from the batch) but only 1 exists now.
        $response = $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 2, 'expected_total' => '1000.00'])
        );

        $response->assertStatus(409);
        $refrend->refresh();
        $this->assertNull($refrend->locked_at);
        $this->assertSame(0, ScholarshipPaymentBatch::count());
    }

    /** @test */
    public function process_with_stale_expected_total_returns_409_and_writes_nothing(): void
    {
        $refrend = $this->makeReadyRefrend();

        $response = $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 1, 'expected_total' => '999.99'])
        );

        $response->assertStatus(409);
        $refrend->refresh();
        $this->assertNull($refrend->locked_at);
        $this->assertSame(0, ScholarshipPaymentBatch::count());
    }

    // ── process(): happy path ────────────────────────────────────────────────

    /** @test */
    public function process_pays_a_fully_ready_batch_atomically(): void
    {
        $refrendA = $this->makeReadyRefrend();
        $refrendB = $this->makeReadyRefrend();

        $response = $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 2, 'expected_total' => '2000.00'])
        );

        $response->assertStatus(200);
        $this->assertTrue($response->json('res'));

        $batchId = $response->json('data.batch_id');
        $this->assertNotNull($batchId);

        $rows = $response->json('data.rows');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('PAID', $row['outcome']);
            $this->assertNull($row['outcome_reason']);
        }

        $this->assertDatabaseHas('scholarship_payment_batches', [
            'id'              => $batchId,
            'generation_id'   => self::GENERATION_ID,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 2,
            'total_amount'    => '2000.00',
            'processed_by_id' => $this->rootAdmin->id,
        ]);

        foreach ([$refrendA, $refrendB] as $refrend) {
            $refrend->refresh();
            $this->assertSame('PAID', $refrend->status->value);
            $this->assertSame('CLOSED', $refrend->workflow_status);
            $this->assertNotNull($refrend->locked_at);
            $this->assertSame((int) $batchId, $refrend->payment_batch_id);
        }
    }

    // ── process(): double-payment impossibility ─────────────────────────────

    /** @test */
    public function reposting_the_same_batch_key_after_success_is_rejected_as_already_paid(): void
    {
        $this->makeReadyRefrend();

        $first = $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 1, 'expected_total' => '1000.00'])
        );
        $first->assertStatus(200);

        $this->assertSame(1, ScholarshipPaymentBatch::count());

        // Re-post the identical batch key/expected values.
        $second = $this->actingAs($this->rootAdmin)->postJson(
            '/api/admin/scholarship-payments/process',
            $this->processPayload(['expected_count' => 1, 'expected_total' => '1000.00'])
        );

        $second->assertStatus(422);
        $blockingRows = $second->json('data.blocking_rows');
        $this->assertCount(1, $blockingRows);
        $this->assertContains('ALREADY_PAID', array_column($blockingRows[0]['blocking_reasons'], 'code'));

        // Still exactly one batch row — double payment is genuinely impossible.
        $this->assertSame(1, ScholarshipPaymentBatch::count());
    }
}
