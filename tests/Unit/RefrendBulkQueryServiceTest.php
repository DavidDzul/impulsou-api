<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipSemesterGrade;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use App\Services\RefrendBulkQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for RefrendBulkQueryService.
 *
 * Because classifyAcademic() is a private method, it is tested indirectly by
 * seeding a ScholarshipSemesterGrade and asserting the returned `academic_status`
 * in the buildTable() response.
 */
class RefrendBulkQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private RefrendBulkQueryService $service;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = $this->app->make(RefrendBulkQueryService::class);
        $this->adminUser = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Creates a simple refrend and the associated bec user.
     * Returns the created ScholarshipRefrend.
     */
    private function makeRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 5,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'base_amount'                  => 2500.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 2500.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    /**
     * Seeds a semester grade for a given user.
     */
    private function seedGrade(int $userId, float $grade): void
    {
        ScholarshipSemesterGrade::create([
            'user_id'         => $userId,
            'grade'           => $grade,
            'semester_year'   => 2026,
            'semester_period' => 1,
        ]);
    }

    private function buildTable(?int $generationId = null, ?string $campus = null): array
    {
        return $this->service->buildTable(
            year:         2026,
            month:        5,
            campus:       $campus,
            generationId: $generationId,
            page:         1,
            perPage:      200,
        );
    }

    /**
     * Creates (or updates) a ScholarshipProfile for the given user with an
     * explicit advance_payment_eligible flag.
     */
    private function seedProfile(int $userId, bool $eligible = true): void
    {
        ScholarshipProfile::factory()->create([
            'user_id'                  => $userId,
            'advance_payment_eligible' => $eligible,
        ]);
    }

    // ── classifyAcademic — tested via buildTable() ────────────────────────────

    /** @test */
    public function academic_status_is_ok_when_grade_is_9_5(): void
    {
        $refrend = $this->makeRefrend();
        $this->seedGrade($refrend->user_id, 9.5);

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertSame('ok', $result['rows'][0]['academic_status']);
        $this->assertSame('9.50', $result['rows'][0]['last_grade']);
    }

    /** @test */
    public function academic_status_is_low_grade_when_grade_is_7_9(): void
    {
        $refrend = $this->makeRefrend();
        $this->seedGrade($refrend->user_id, 7.9);

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertSame('low_grade', $result['rows'][0]['academic_status']);
    }

    /** @test */
    public function academic_status_is_ok_when_grade_is_exactly_8_0(): void
    {
        $refrend = $this->makeRefrend();
        $this->seedGrade($refrend->user_id, 8.0);

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertSame('ok', $result['rows'][0]['academic_status'],
            'Grade exactly 8.0 should be classified as ok (>= 8.0 threshold).');
    }

    /** @test */
    public function academic_status_is_missing_subjects_when_no_grade_exists(): void
    {
        // No seedGrade() call — user has no semester grade record
        $this->makeRefrend();

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertSame('missing_subjects', $result['rows'][0]['academic_status']);
        $this->assertNull($result['rows'][0]['last_grade']);
    }

    // ── buildTable() structure assertions ─────────────────────────────────────

    /** @test */
    public function build_table_returns_correct_meta_structure(): void
    {
        $this->makeRefrend();
        $this->makeRefrend();

        $result = $this->buildTable();

        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('meta', $result);

        $meta = $result['meta'];
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('current_page', $meta);
        $this->assertArrayHasKey('last_page', $meta);

        $this->assertSame(2, $meta['total']);
        $this->assertCount(2, $result['rows']);
    }

    /** @test */
    public function build_table_returns_empty_rows_when_no_refrendos_exist(): void
    {
        $result = $this->buildTable();

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['meta']['total']);
    }

    /** @test */
    public function incidents_count_increments_for_academic_issues(): void
    {
        $refrend = $this->makeRefrend();
        // Grade below 8.0 → academic issue → +1 incident
        $this->seedGrade($refrend->user_id, 6.0);

        $result = $this->buildTable();

        $this->assertSame(1, $result['rows'][0]['incidents_count'],
            'A low grade should add 1 to incidents_count.');
    }

    // ── S-BULK-01: total_to_pay includes carryover ────────────────────────────

    /** @test */
    public function total_to_pay_equals_final_amount_plus_amount_pending_from_previous(): void
    {
        $this->makeRefrend([
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 200.00,
        ]);

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertSame(
            1200.0,
            $result['rows'][0]['refrend']['total_to_pay'],
            'total_to_pay must be final_amount + amount_pending_from_previous.'
        );
    }

    // ── S-BULK-02: total_to_pay when carryover is zero ───────────────────────

    /** @test */
    public function total_to_pay_equals_final_amount_when_amount_pending_is_zero(): void
    {
        // amount_pending_from_previous defaults to 0 (NOT NULL column) — no carryover.
        $this->makeRefrend([
            'final_amount'                 => 800.00,
            'amount_pending_from_previous' => 0,
        ]);

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertSame(
            800.0,
            $result['rows'][0]['refrend']['total_to_pay'],
            'total_to_pay must equal final_amount when there is no pending carryover.'
        );
    }

    // ── B5: chip reflects the payable (window 3 months / top-2) subset only ───

    private function makeWithholdingForUser(int $userId, int $periodYear, int $periodMonth, float $withheld = 100.00): ScholarshipWithholding
    {
        $originRefrend = $this->makeRefrend([
            'user_id'      => $userId,
            'period_year'  => $periodYear,
            'period_month' => $periodMonth,
            'status'       => 'WITHHELD',
        ]);

        return ScholarshipWithholding::create([
            'user_id'           => $userId,
            'origin_refrend_id' => $originRefrend->id,
            'period_year'       => $periodYear,
            'period_month'      => $periodMonth,
            'withheld_amount'   => number_format($withheld, 2, '.', ''),
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);
    }

    /** @test */
    public function chip_counts_and_sums_only_payable_rows(): void
    {
        // Page/current period is 2026-05 (the makeRefrend()/buildTable() default).
        $refrend = $this->makeRefrend();
        $this->makeWithholdingForUser($refrend->user_id, 2026, 4, 100.00); // offset 1, payable
        $this->makeWithholdingForUser($refrend->user_id, 2026, 3, 150.00); // offset 2, payable
        $this->makeWithholdingForUser($refrend->user_id, 2026, 2, 999.00); // offset 3, in-window but rank 3rd — excluded

        $result = $this->buildTable();

        $this->assertSame(2, $result['rows'][0]['pending_withholding_count']);
        $this->assertSame('250.00', $result['rows'][0]['pending_withholding_amount']);
    }

    /** @test */
    public function chip_excludes_stale_out_of_window_rows(): void
    {
        $refrend = $this->makeRefrend();
        $this->makeWithholdingForUser($refrend->user_id, 2026, 1, 500.00); // offset 4, out of window

        $result = $this->buildTable();

        $this->assertSame(0, $result['rows'][0]['pending_withholding_count']);
        $this->assertNull($result['rows'][0]['pending_withholding_amount']);
    }

    /** @test */
    public function chip_count_has_parity_with_the_withholding_list_endpoint_eligible_count(): void
    {
        $admin   = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $refrend = $this->makeRefrend();
        $this->makeWithholdingForUser($refrend->user_id, 2026, 4, 100.00); // offset 1, payable
        $this->makeWithholdingForUser($refrend->user_id, 2026, 3, 150.00); // offset 2, payable
        $this->makeWithholdingForUser($refrend->user_id, 2026, 2, 999.00); // offset 3, rank-excluded
        $this->makeWithholdingForUser($refrend->user_id, 2025, 12, 999.00); // offset 5, window-excluded

        $chipResult = $this->buildTable();

        $endpointResponse = $this->actingAs($admin)->getJson(
            "/api/admin/users/{$refrend->user_id}/scholarship-withholdings?relative_year=2026&relative_month=5"
        );
        $endpointResponse->assertOk();

        $this->assertSame(
            $endpointResponse->json('meta.eligible_count'),
            $chipResult['rows'][0]['pending_withholding_count'],
            'The bulk-table chip count must match the withholding list endpoint eligible_count for the same period.'
        );
    }

    /** @test */
    public function chip_computation_issues_exactly_one_withholdings_query_regardless_of_user_count(): void
    {
        $refrendA = $this->makeRefrend();
        $refrendB = $this->makeRefrend();
        $this->makeWithholdingForUser($refrendA->user_id, 2026, 4, 100.00);
        $this->makeWithholdingForUser($refrendB->user_id, 2026, 3, 200.00);

        DB::enableQueryLog();
        $this->buildTable();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $withholdingQueries = array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'scholarship_withholdings')
        );

        $this->assertCount(
            1,
            $withholdingQueries,
            'Exactly one query against scholarship_withholdings must be issued for N users (no N+1).'
        );
    }

    // ── advance_payment_eligible row data (beca-pago-adelantado-cert) ─────────
    //
    // PIVOT: this is no longer a server-side WHERE-clause filter — the row
    // simply carries the flag (like incidents_count/pending_withholding_count)
    // and the "Con incidencias"-style client-side filter operates on the
    // already-fetched rows. These tests assert the field is present and
    // correctly typed/defaulted in the returned row data.

    /** @test */
    public function row_reflects_true_when_profile_is_flagged_eligible(): void
    {
        $refrend = $this->makeRefrend();
        $this->seedProfile($refrend->user_id, true);

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertTrue($result['rows'][0]['advance_payment_eligible']);
    }

    /** @test */
    public function row_reflects_false_when_profile_is_not_flagged_eligible(): void
    {
        $refrend = $this->makeRefrend();
        $this->seedProfile($refrend->user_id, false);

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertFalse($result['rows'][0]['advance_payment_eligible']);
    }

    /** @test */
    public function row_defaults_to_false_when_becario_has_no_profile(): void
    {
        // No seedProfile() call — the LEFT JOIN yields NULL for every sp.*
        // column. Unlike the genuinely-nullable profile_discount_* fields,
        // advance_payment_eligible must be normalized to false (boolean, not
        // null) so the frontend never receives a null in a boolean field.
        $this->makeRefrend();

        $result = $this->buildTable();

        $this->assertCount(1, $result['rows']);
        $this->assertFalse($result['rows'][0]['advance_payment_eligible']);
    }
}
