<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Generation;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkTableEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an ADMIN user for all tests
        $this->admin = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                   => $user->id,
            'period_year'               => 2026,
            'period_month'              => 5,
            'refrend_type'              => RefrendType::NORMAL->value,
            'status'                    => RefrendStatus::DRAFT->value,
            'base_amount'               => 2500.00,
            'discount_percentage'       => 0,
            'discount_amount'           => 0,
            'final_amount'              => 2500.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'             => 'Test Becario',
            'snapshot_generation'       => null,
            'snapshot_generation_id'    => null,
            'snapshot_campus'           => 'MERIDA',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function url(array $params = []): string
    {
        $query = http_build_query(array_merge([
            'year'  => 2026,
            'month' => 5,
        ], $params));

        return '/api/admin/scholarship-refrends/bulk-table?' . $query;
    }

    // ── TASK-11-1 Tests ───────────────────────────────────────────────────────

    /** @test */
    public function it_returns_422_when_year_is_missing(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/scholarship-refrends/bulk-table?month=5');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['year']);
    }

    /** @test */
    public function it_returns_422_when_month_is_missing(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/scholarship-refrends/bulk-table?year=2026');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['month']);
    }

    /** @test */
    public function it_returns_422_when_year_is_invalid(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/scholarship-refrends/bulk-table?year=2010&month=5');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['year']);
    }

    /** @test */
    public function it_returns_200_with_empty_data_when_no_records_exist(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson($this->url());

        $response->assertStatus(200);
        $response->assertJson([
            'res'  => true,
            'data' => [],
        ]);
        $this->assertSame(0, $response->json('meta.total'));
    }

    /** @test */
    public function it_returns_correct_row_shape_for_each_refrend(): void
    {
        $this->makeRefrend();

        $response = $this->actingAs($this->admin)
            ->getJson($this->url());

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);

        $row = $rows[0];

        // Assert all expected keys are present
        $expectedKeys = [
            'id',
            'user_id',
            'full_name',
            'campus',
            'snapshot_generation',
            'snapshot_generation_id',
            'period_year',
            'period_month',
            'status',
            'refrend_type',
            'base_amount',
            'discount_percentage',
            'discount_amount',
            'final_amount',
            'total_to_pay',
            'atencion_labels',
            'atencion_observations',
            'atencion_reviewed_at',
            'pedagogia_observations',
            'pedagogia_reviewed_at',
            'locked_at',
            'attendance',
            'average_grade',
            'academic_status',
            'incidents_count',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $row, "Expected key '{$key}' missing from row.");
        }

        // Assert the attendance sub-keys
        $this->assertArrayHasKey('present', $row['attendance']);
        $this->assertArrayHasKey('late', $row['attendance']);
        $this->assertArrayHasKey('absent_unjustified', $row['attendance']);
    }

    /** @test */
    public function query_count_is_bounded_when_multiple_refrends_exist(): void
    {
        // Create 3 refrends for different users
        $this->makeRefrend();
        $this->makeRefrend();
        $this->makeRefrend();

        DB::enableQueryLog();

        $response = $this->actingAs($this->admin)
            ->getJson($this->url());

        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));

        // Assert N+1 is bounded: Q1 (paginate count) + Q1b (paginate select) +
        // Q2 (attendance aggregates) + Q3 (last grades) + auth/session queries
        // We allow up to 10 to be safe; the important constraint is it does NOT scale with row count
        $this->assertLessThanOrEqual(10, count($queryLog),
            'Query count exceeded 10 — possible N+1. Queries: ' . count($queryLog));
    }

    /** @test */
    public function it_filters_by_generation_id(): void
    {
        $gen1 = Generation::create([
            'generation_name'   => 'Gen 2024-A',
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);

        $gen2 = Generation::create([
            'generation_name'   => 'Gen 2024-B',
            'campus'            => 'MERIDA',
            'generation_active' => true,
        ]);

        // Refrend for gen1
        $this->makeRefrend(['snapshot_generation_id' => $gen1->id]);
        // Refrend for gen2
        $this->makeRefrend(['snapshot_generation_id' => $gen2->id]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url(['generation_id' => $gen1->id]));

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($gen1->id, $rows[0]['snapshot_generation_id']);
    }

    /** @test */
    public function it_filters_by_campus(): void
    {
        // Refrend in MERIDA
        $this->makeRefrend(['snapshot_campus' => 'MERIDA']);
        // Refrend in VALLADOLID
        $this->makeRefrend(['snapshot_campus' => 'VALLADOLID']);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url(['campus' => 'MERIDA']));

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('MERIDA', $rows[0]['campus']);
    }

    /** @test */
    public function it_returns_correct_meta_structure(): void
    {
        $this->makeRefrend();
        $this->makeRefrend();

        $response = $this->actingAs($this->admin)
            ->getJson($this->url());

        $response->assertStatus(200);

        $meta = $response->json('meta');
        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('per_page', $meta);
        $this->assertArrayHasKey('current_page', $meta);
        $this->assertArrayHasKey('last_page', $meta);
        $this->assertSame(2, $meta['total']);
    }

    // ── Pending withholding indicator (Pedagogía/Atención) ─────────────────────

    /** @test */
    public function pending_withholding_count_and_amount_are_aggregated_across_all_periods_including_current(): void
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        // Refrend for the queried period (May 2026) — this is the row the
        // bulk-table endpoint returns for this test.
        $mayRefrend = $this->makeRefrend(['user_id' => $user->id, 'period_month' => 5]);
        // Two older refrends outside the queried period; their withholdings
        // must still count toward the accumulated total (design ADR D1/D2).
        $marRefrend = $this->makeRefrend(['user_id' => $user->id, 'period_month' => 3]);
        $aprRefrend = $this->makeRefrend(['user_id' => $user->id, 'period_month' => 4]);

        // 3 PENDING withholdings with a pending balance, one of them
        // originated in the current (May) refrend itself — design ADR D2:
        // the total includes it, it is not excluded.
        ScholarshipWithholding::create([
            'user_id' => $user->id, 'origin_refrend_id' => $mayRefrend->id,
            'period_year' => 2026, 'period_month' => 5,
            'withheld_amount' => 300.00, 'paid_amount' => 0, 'status' => 'PENDING',
        ]);
        ScholarshipWithholding::create([
            'user_id' => $user->id, 'origin_refrend_id' => $marRefrend->id,
            'period_year' => 2026, 'period_month' => 3,
            'withheld_amount' => 150.00, 'paid_amount' => 50.00, 'status' => 'PENDING',
        ]);
        ScholarshipWithholding::create([
            'user_id' => $user->id, 'origin_refrend_id' => $aprRefrend->id,
            'period_year' => 2026, 'period_month' => 4,
            'withheld_amount' => 200.00, 'paid_amount' => 0, 'status' => 'PENDING',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->url(['campus' => 'MERIDA']));

        $response->assertStatus(200);
        $rows = $response->json('data');
        $this->assertCount(1, $rows);

        $this->assertSame(3, $rows[0]['pending_withholding_count']);
        $this->assertSame('600.00', $rows[0]['pending_withholding_amount']);
        $this->assertIsString($rows[0]['pending_withholding_amount']);
    }

    /** @test */
    public function settled_withholdings_are_excluded_and_becarios_without_any_get_null_amount(): void
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);
        $refrend           = $this->makeRefrend(['user_id' => $user->id, 'period_month' => 5]);
        $paidOriginRefrend = $this->makeRefrend(['user_id' => $user->id, 'period_month' => 1]);

        // Fully settled (status PAID, no remaining balance) — must not count.
        ScholarshipWithholding::create([
            'user_id' => $user->id, 'origin_refrend_id' => $paidOriginRefrend->id,
            'period_year' => 2026, 'period_month' => 1,
            'withheld_amount' => 100.00, 'paid_amount' => 100.00, 'status' => 'PAID',
        ]);

        $userWithoutWithholding = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);
        $this->makeRefrend(['user_id' => $userWithoutWithholding->id, 'period_month' => 5]);

        DB::enableQueryLog();
        $response = $this->actingAs($this->admin)
            ->getJson($this->url(['campus' => 'MERIDA']));
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);
        $rows = collect($response->json('data'))->keyBy(fn ($r) => $r['refrend']['user_id']);

        $this->assertSame(0, $rows[$user->id]['pending_withholding_count']);
        $this->assertNull($rows[$user->id]['pending_withholding_amount']);
        $this->assertSame(0, $rows[$userWithoutWithholding->id]['pending_withholding_count']);
        $this->assertNull($rows[$userWithoutWithholding->id]['pending_withholding_amount']);

        // Aggregate is one query per batch, not one per row — bounded, not
        // scaling with the number of becarios in the response.
        $this->assertLessThanOrEqual(10, count($queryLog),
            'Query count exceeded 10 — the withholding aggregate must not add a per-row query.');
    }
}
