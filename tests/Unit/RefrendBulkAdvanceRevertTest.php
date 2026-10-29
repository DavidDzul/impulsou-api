<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\RefrendBulkQueryService;
use App\Services\Scholarship\AdvanceRevertEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S2 (spec R7 / S11, S12, S13, S13b):
 * `active_advance` and `advance_revert_block` on the bulk master table rows.
 * The block code must always equal what AdvanceRevertEligibility reports.
 */
class RefrendBulkAdvanceRevertTest extends TestCase
{
    use RefreshDatabase;

    private RefrendBulkQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(RefrendBulkQueryService::class);
    }

    /** @param array<string, mixed> $originOverrides */
    private function makeAdvance(array $originOverrides = [], int $monthsCount = 2, string $headerStatus = 'ACTIVE'): array
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        ScholarshipProfile::create([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => '2026-01-01',
            'config_set_at'       => now(),
        ]);

        $origin = $this->makeRefrend($user->id, 5, $originOverrides);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 5,
            'months_count'        => $monthsCount,
            'total_amount'        => 1250.50 * $monthsCount,
            'status'              => $headerStatus,
        ]);

        $months  = [];
        $futures = [];
        for ($i = 1; $i <= $monthsCount; $i++) {
            $future    = $this->makeRefrend($user->id, 5 + $i, ['created_via' => 'ADVANCE_PAYMENT']);
            $futures[] = $future;
            $months[]  = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id,
                'user_id'            => $user->id,
                'period_year'        => 2026,
                'period_month'       => 5 + $i,
                'amount'             => 1250.50,
                'refrend_id'         => $future->id,
                'status'             => 'PENDING',
            ]);
        }

        return compact('user', 'origin', 'header', 'months', 'futures');
    }

    /** @param array<string, mixed> $overrides */
    private function makeRefrend(int $userId, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => $month,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function rowsById(int $month = 5): array
    {
        $result = $this->service->buildTable(2026, $month, null, null, 1, 200);

        $byId = [];
        foreach ($result['rows'] as $row) {
            $byId[$row['refrend']['id']] = $row;
        }

        return $byId;
    }

    /** @test */
    public function origin_row_exposes_the_active_advance_and_no_block_when_revertable(): void
    {
        $f   = $this->makeAdvance();
        $row = $this->rowsById()[$f['origin']->id];

        $this->assertNull($row['advance_revert_block']);
        $this->assertSame([
            'id'           => $f['header']->id,
            'status'       => 'ACTIVE',
            'months_count' => 2,
            'total_amount' => '2501.00',
            'months'       => [
                [
                    'id'           => $f['months'][0]->id,
                    'period_year'  => 2026,
                    'period_month' => 6,
                    'amount'       => '1250.50',
                    'status'       => 'PENDING',
                ],
                [
                    'id'           => $f['months'][1]->id,
                    'period_year'  => 2026,
                    'period_month' => 7,
                    'amount'       => '1250.50',
                    'status'       => 'PENDING',
                ],
            ],
        ], $row['active_advance']);
    }

    /** @test */
    public function origin_row_with_incidents_stays_revertable(): void
    {
        $f = $this->makeAdvance(['workflow_status' => 'CON_INCIDENCIA']);
        $f['origin']->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'OTRO',
            'description'       => 'manual',
            'priority'          => 'LOW',
            'created_by_id'     => $f['user']->id,
        ]);

        $row = $this->rowsById()[$f['origin']->id];

        $this->assertNotNull($row['active_advance']);
        $this->assertNull($row['advance_revert_block']);
    }

    /** @test */
    public function a_paid_origin_reports_origin_paid_block(): void
    {
        $f   = $this->makeAdvance(['status' => 'PAID']);
        $row = $this->rowsById()[$f['origin']->id];

        $this->assertNotNull($row['active_advance']);
        $this->assertSame('ADVANCE_ORIGIN_PAID', $row['advance_revert_block']);
    }

    /** @test */
    public function rows_without_an_advance_have_null_flags(): void
    {
        $user   = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $origin = $this->makeRefrend($user->id, 5);

        $row = $this->rowsById()[$origin->id];

        $this->assertNull($row['active_advance']);
        $this->assertNull($row['advance_revert_block']);
    }

    /** @test */
    public function the_advance_covered_future_row_is_advance_paid_without_active_advance(): void
    {
        $f   = $this->makeAdvance();
        $row = $this->rowsById(6)[$f['futures'][0]->id];

        $this->assertTrue($row['advance_paid']);
        $this->assertNull($row['active_advance']);
        $this->assertNull($row['advance_revert_block']);
    }

    /**
     * @test
     * @dataProvider nonActiveHeaderStatuses
     */
    public function a_non_active_header_does_not_expose_an_active_advance(string $status): void
    {
        $f   = $this->makeAdvance([], 2, $status);
        $row = $this->rowsById()[$f['origin']->id];

        $this->assertNull($row['active_advance']);
        $this->assertNull($row['advance_revert_block']);
    }

    public function nonActiveHeaderStatuses(): array
    {
        return [['CANCELLED'], ['PARTIALLY_CANCELLED']];
    }

    /** @test */
    public function bulk_block_code_matches_the_eligibility_service_on_the_same_fixtures(): void
    {
        $eligible = $this->makeAdvance();
        $paid     = $this->makeAdvance(['status' => 'PAID']);
        $locked   = $this->makeAdvance(['workflow_status' => 'CLOSED']);
        $settled  = $this->makeAdvance();
        $settled['months'][0]->update(['status' => 'REACHED']);
        $dirty = $this->makeAdvance();
        $dirty['futures'][1]->update(['resolution_type' => 'SIN_ESPECIFICAR']);

        $eligibility = $this->app->make(AdvanceRevertEligibility::class);
        $rows        = $this->rowsById();

        foreach ([$eligible, $paid, $locked, $settled, $dirty] as $fixture) {
            $id = $fixture['origin']->id;
            $this->assertSame($eligibility->evaluate($id), $rows[$id]['advance_revert_block']);
        }
    }

    /** @test */
    public function extra_queries_do_not_grow_with_the_number_of_rows(): void
    {
        $this->makeAdvance();

        $countQueries = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->service->buildTable(2026, 5, null, null, 1, 200);

            return $queries;
        };

        $before = $countQueries();

        $this->makeAdvance();
        $this->makeAdvance(['status' => 'PAID'], 3);
        $this->makeAdvance();

        $after = $countQueries();

        $this->assertSame($before, $after, 'Bulk advance flags must be batch-computed (no N+1).');
    }
}
