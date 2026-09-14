<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
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
            'carryover_percentage'    => 50.00,
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
        $this->assertSame('50.00', $data['carryover_percentage']);

        $this->assertSame('Llegó tarde dos veces.', $data['atencion_observations']);
        $this->assertSame('Bajo rendimiento en cálculo.', $data['pedagogia_observations']);
        $this->assertSame('Aprobado tras revisión.', $data['resolution_notes']);

        $this->assertSame('1000.00', $data['amount_breakdown']['final_amount']);
        $this->assertSame('450.00', $data['amount_breakdown']['amount_pending_from_previous']);
        $this->assertSame('1450.00', $data['amount_breakdown']['total_to_pay']);
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
