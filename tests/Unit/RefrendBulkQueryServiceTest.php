<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipSemesterGrade;
use App\Models\User;
use App\Services\RefrendBulkQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function buildTable(?int $generationId = null): array
    {
        return $this->service->buildTable(
            year:         2026,
            month:        5,
            campus:       null,
            generationId: $generationId,
        );
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
        $this->assertSame(9.5, $result['rows'][0]['average_grade']);
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
        $this->assertNull($result['rows'][0]['average_grade']);
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
}
