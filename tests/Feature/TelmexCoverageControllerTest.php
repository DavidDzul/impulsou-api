<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-iu, PR3b. Covers `api/admin/telmex-coverages` —
 * HTTP layer over the PR3a actions/ledger. Permission gates are enforced
 * via the real `permission:` middleware + RoleSeeder (not mocked), per the
 * launch prompt's "real enforcement" requirement.
 */
class TelmexCoverageControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $rootAdmin;
    private User $noPermAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');

        $this->noPermAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function makeProfile(string $type = 'TELMEX', array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        return ScholarshipProfile::create(array_merge([
            'user_id'            => $user->id,
            'scholarship_type'   => $type,
            'monthly_amount'     => 1800.00,
            'monto_apoyo'        => 150.00,
            'payment_start_date' => now()->toDateString(),
        ], $overrides));
    }

    private function makeCoverage(array $overrides = []): TelmexCoverage
    {
        $profile = $this->makeProfile();

        return TelmexCoverage::create(array_merge([
            'user_id'                        => $profile->user_id,
            'scholarship_type_at_activation'  => 'TELMEX',
            'start_period'                    => '2026-01-01',
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
            'activated_by_id'                 => $this->rootAdmin->id,
        ], $overrides));
    }

    private function makePaidCoveredRefrend(TelmexCoverage $coverage, int $year, int $month, float $covered): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'                        => $coverage->user_id,
            'period_year'                    => $year,
            'period_month'                   => $month,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::PAID->value,
            'workflow_status'                => 'PAID',
            'base_amount'                    => 0,
            'snapshot_telmex_covered_amount' => $covered,
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'final_amount'                   => $covered,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => 'TELMEX',
        ]);
    }

    // ── index ──────────────────────────────────────────────────────────

    /** @test */
    public function index_lists_coverages_with_denormalized_becario_fields_and_ledger(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-01-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverage, 2026, 1, 1950.0);

        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/telmex-coverages');

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertSame($coverage->id, $row['id']);
        $this->assertNotEmpty($row['becario_name']);
        $this->assertSame('MERIDA', $row['campus']);
        $this->assertSame('1950.00', $row['advanced']);
        $this->assertSame('0.00', $row['repaid']);
        $this->assertSame('1950.00', $row['balance']);
        $this->assertTrue($row['has_paid_covered_month']);
        $this->assertSame('2026-01-01', $row['start_period']);
    }

    /** @test */
    public function index_filters_by_status(): void
    {
        $this->makeCoverage(['status' => 'ACTIVA']);
        $cancelled = $this->makeCoverage(['status' => 'CANCELADA', 'cancel_reason' => 'Motivo de cancelación válido.']);

        $response = $this->actingAs($this->rootAdmin)
            ->getJson('/api/admin/telmex-coverages?status=CANCELADA');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($cancelled->id, $response->json('data.0.id'));
    }

    /** @test */
    public function index_filters_by_search_on_becario_name(): void
    {
        $coverage = $this->makeCoverage();
        $user     = User::find($coverage->user_id);
        $user->update(['first_name' => 'Zoe', 'last_name' => 'Unica']);

        $this->makeCoverage();

        $response = $this->actingAs($this->rootAdmin)
            ->getJson('/api/admin/telmex-coverages?search=zoe');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($coverage->id, $response->json('data.0.id'));
    }

    /** @test */
    public function index_without_permission_returns_403(): void
    {
        $response = $this->actingAs($this->noPermAdmin)->getJson('/api/admin/telmex-coverages');

        $response->assertStatus(403);
    }

    // ── eligible ───────────────────────────────────────────────────────

    /** @test */
    public function eligible_excludes_becarios_with_any_existing_coverage_including_cancelada(): void
    {
        $eligibleProfile = $this->makeProfile('TELMEX_IU');
        $cancelledProfile = $this->makeProfile('TELMEX');
        TelmexCoverage::create([
            'user_id'                        => $cancelledProfile->user_id,
            'scholarship_type_at_activation'  => 'TELMEX',
            'start_period'                    => '2025-01-01',
            'status'                          => 'CANCELADA',
            'cancel_reason'                   => 'Motivo de cancelación válido.',
        ]);
        $activeCoverage = $this->makeCoverage();

        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/telmex-coverages/eligible');

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($eligibleProfile->user_id, $ids);
        $this->assertNotContains($cancelledProfile->user_id, $ids);
        $this->assertNotContains($activeCoverage->user_id, $ids);
    }

    /** @test */
    public function eligible_without_permission_returns_403(): void
    {
        $response = $this->actingAs($this->noPermAdmin)->getJson('/api/admin/telmex-coverages/eligible');

        $response->assertStatus(403);
    }

    // ── show (statement) ───────────────────────────────────────────────

    /** @test */
    public function show_returns_statement_with_coverage_months_and_payments(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-01-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverage, 2026, 1, 1950.0);
        $coverage->payments()->create(['amount' => 500, 'paid_at' => '2026-02-01', 'created_by_id' => $this->rootAdmin->id]);

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/telmex-coverages/{$coverage->id}");

        $response->assertStatus(200);
        $this->assertSame($coverage->id, $response->json('data.coverage.id'));
        $this->assertSame('1950.00', $response->json('data.coverage.advanced'));
        $this->assertSame('500.00', $response->json('data.coverage.repaid'));
        $this->assertCount(1, $response->json('data.months'));
        $this->assertCount(1, $response->json('data.payments'));
        $this->assertSame('500.00', $response->json('data.payments.0.amount'));
    }

    /** @test */
    public function show_flags_covered_months_that_also_had_a_temporary_increase(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-02-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverage, 2026, 1, 1950.0);
        $this->makePaidCoveredRefrend($coverage, 2026, 2, 1950.0)
            ->update(['snapshot_temporary_increase_amount' => 300]);

        $response = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/telmex-coverages/{$coverage->id}");

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.months.0.has_temporary_increase'));
        $this->assertTrue($response->json('data.months.1.has_temporary_increase'));
    }

    /** @test */
    public function show_returns_months_in_the_shape_the_panel_renders(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-02-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverage, 2026, 1, 1950.0)->update(['payment_batch_id' => null]);
        $this->makePaidCoveredRefrend($coverage, 2026, 2, 1950.0)
            ->update(['status' => RefrendStatus::DRAFT->value, 'workflow_status' => 'LISTO_PARA_PAGO']);

        $months = $this->actingAs($this->rootAdmin)
            ->getJson("/api/admin/telmex-coverages/{$coverage->id}")
            ->assertStatus(200)
            ->json('data.months');

        $this->assertSame('2026-01-01', $months[0]['period']);
        $this->assertEquals(1950.0, $months[0]['covered_amount']);
        $this->assertTrue($months[0]['is_paid']);
        $this->assertNull($months[0]['payment_batch_id']);
        $this->assertSame('2026-02-01', $months[1]['period']);
        $this->assertFalse($months[1]['is_paid']);
    }

    /** @test */
    public function show_without_permission_returns_403(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->actingAs($this->noPermAdmin)
            ->getJson("/api/admin/telmex-coverages/{$coverage->id}");

        $response->assertStatus(403);
    }

    // ── store (activate) ───────────────────────────────────────────────

    /** @test */
    public function store_activates_a_new_coverage(): void
    {
        $profile = $this->makeProfile();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/telmex-coverages', [
            'user_id'      => $profile->user_id,
            'start_period' => '2026-02-01',
            'notes'        => 'Activación inicial.',
        ]);

        $response->assertStatus(201);
        $this->assertSame('ACTIVA', $response->json('data.status'));
        $this->assertDatabaseHas('scholarship_telmex_coverages', [
            'user_id' => $profile->user_id,
            'status'  => 'ACTIVA',
        ]);
    }

    /** @test */
    public function store_requires_user_id_and_start_period(): void
    {
        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/telmex-coverages', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id', 'start_period']);
    }

    /** @test */
    public function store_maps_domain_exception_to_422(): void
    {
        $profile = $this->makeProfile('IU', ['monto_apoyo' => null]);

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/telmex-coverages', [
            'user_id'      => $profile->user_id,
            'start_period' => '2026-02-01',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('msg'));
    }

    /** @test */
    public function store_without_permission_returns_403(): void
    {
        $profile = $this->makeProfile();

        $response = $this->actingAs($this->noPermAdmin)->postJson('/api/admin/telmex-coverages', [
            'user_id'      => $profile->user_id,
            'start_period' => '2026-02-01',
        ]);

        $response->assertStatus(403);
    }

    // ── end ────────────────────────────────────────────────────────────

    /** @test */
    public function end_sets_end_period_one_month_before_telmex_start(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->actingAs($this->rootAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/end", [
                'telmex_start_period' => '2026-04-01',
            ]);

        $response->assertStatus(200);
        $this->assertSame('2026-03-01', $response->json('data.end_period'));
        $this->assertSame('EN_COBRO', $response->json('data.status'));
    }

    /** @test */
    public function end_without_permission_returns_403(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->actingAs($this->noPermAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/end", [
                'telmex_start_period' => '2026-04-01',
            ]);

        $response->assertStatus(403);
    }

    // ── cancel ─────────────────────────────────────────────────────────

    /** @test */
    public function cancel_rejects_a_reason_shorter_than_ten_characters(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->actingAs($this->rootAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/cancel", ['reason' => 'corto']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['reason']);
    }

    /** @test */
    public function cancel_cancels_with_a_valid_reason(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->actingAs($this->rootAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/cancel", [
                'reason' => 'El becario abandonó el programa académico.',
            ]);

        $response->assertStatus(200);
        $this->assertSame('CANCELADA', $response->json('data.status'));
    }

    /** @test */
    public function cancel_without_permission_returns_403(): void
    {
        $coverage = $this->makeCoverage();

        $response = $this->actingAs($this->noPermAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/cancel", [
                'reason' => 'El becario abandonó el programa académico.',
            ]);

        $response->assertStatus(403);
    }

    // ── reactivate ─────────────────────────────────────────────────────

    /** @test */
    public function reactivate_reactivates_a_cancelada_coverage_with_no_paid_month(): void
    {
        $coverage = $this->makeCoverage([
            'status'        => 'CANCELADA',
            'cancel_reason' => 'Activado por error inicialmente.',
            'cancelled_at'  => now(),
        ]);

        $response = $this->actingAs($this->rootAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/reactivate");

        $response->assertStatus(200);
        $this->assertSame('ACTIVA', $response->json('data.status'));
    }

    /** @test */
    public function reactivate_without_permission_returns_403(): void
    {
        $coverage = $this->makeCoverage(['status' => 'CANCELADA', 'cancel_reason' => 'Motivo de cancelación válido.']);

        $response = $this->actingAs($this->noPermAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/reactivate");

        $response->assertStatus(403);
    }

    // ── repayments: register ───────────────────────────────────────────

    /** @test */
    public function store_payment_registers_a_repayment_on_en_cobro(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-01-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverage, 2026, 1, 1000.0);

        $response = $this->actingAs($this->rootAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/payments", [
                'amount'  => 400,
                'paid_at' => '2026-02-01',
            ]);

        $response->assertStatus(201);
        $this->assertSame('400.00', $response->json('data.amount'));
    }

    /**
     * sdd/telmex-cobertura-iu, decisions-3 #1926: repayments are rejected
     * on ACTIVA — Telmex hasn't started paying yet, so there is nothing to
     * repay.
     *
     * @test
     */
    public function store_payment_rejects_on_activa_coverage(): void
    {
        $coverage = $this->makeCoverage(['status' => 'ACTIVA']);

        $response = $this->actingAs($this->rootAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/payments", ['amount' => 100]);

        $response->assertStatus(422);
    }

    /** @test */
    public function store_payment_without_permission_returns_403(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-01-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverage, 2026, 1, 1000.0);

        $response = $this->actingAs($this->noPermAdmin)
            ->postJson("/api/admin/telmex-coverages/{$coverage->id}/payments", ['amount' => 100]);

        $response->assertStatus(403);
    }

    // ── repayments: void ───────────────────────────────────────────────

    /** @test */
    public function void_payment_voids_with_a_reason(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-01-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverage, 2026, 1, 1000.0);
        $payment = $coverage->payments()->create(['amount' => 400, 'paid_at' => '2026-02-01']);

        $response = $this->actingAs($this->rootAdmin)
            ->patchJson("/api/admin/telmex-coverages/{$coverage->id}/payments/{$payment->id}/void", [
                'void_reason' => 'Depósito duplicado por error de captura.',
            ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.is_voided'));
    }

    /** @test */
    public function void_payment_returns_404_when_payment_does_not_belong_to_the_url_coverage(): void
    {
        $coverageA = $this->makeCoverage(['end_period' => '2026-01-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverageA, 2026, 1, 1000.0);
        $paymentA = $coverageA->payments()->create(['amount' => 400, 'paid_at' => '2026-02-01']);

        $coverageB = $this->makeCoverage(['end_period' => '2026-01-01', 'status' => 'EN_COBRO']);

        $response = $this->actingAs($this->rootAdmin)
            ->patchJson("/api/admin/telmex-coverages/{$coverageB->id}/payments/{$paymentA->id}/void", [
                'void_reason' => 'Motivo suficientemente largo para pasar la validación.',
            ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function void_payment_without_permission_returns_403(): void
    {
        $coverage = $this->makeCoverage(['end_period' => '2026-01-01', 'status' => 'EN_COBRO']);
        $this->makePaidCoveredRefrend($coverage, 2026, 1, 1000.0);
        $payment = $coverage->payments()->create(['amount' => 400, 'paid_at' => '2026-02-01']);

        $response = $this->actingAs($this->noPermAdmin)
            ->patchJson("/api/admin/telmex-coverages/{$coverage->id}/payments/{$payment->id}/void", [
                'void_reason' => 'Motivo suficientemente largo para pasar la validación.',
            ]);

        $response->assertStatus(403);
    }
}
