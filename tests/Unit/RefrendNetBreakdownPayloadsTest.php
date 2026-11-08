<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\RefrendBulkQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * sdd/descuento-beca-apoyo PR3: psicol-facing `net_breakdown` on the
 * approval table (RefrendBulkQueryService) and on a user's refrends
 * (ScholarshipRefrend accessor + controller forUser). Additive only.
 */
class RefrendNetBreakdownPayloadsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRefrend(array $overrides = [], ?User $user = null): ScholarshipRefrend
    {
        $user ??= User::factory()->create([
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
            'base_amount'                  => 2000.00,
            'snapshot_gross_amount'        => 2000.00,
            'snapshot_monto_apoyo'         => 200.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 300.00,
            'final_amount'                 => 1700.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function bulkRow(): array
    {
        $table = app(RefrendBulkQueryService::class)->buildTable(
            year: 2026, month: 5, campus: null, generationId: null, page: 1, perPage: 200,
        );

        return $table['rows'][0];
    }

    private function assertInvariant(array $nb): void
    {
        $sum = round(
            (float) $nb['beca'] + (float) $nb['apoyo'] + (float) $nb['pago_iu']
            + (float) $nb['temporary_increase'] + (float) $nb['telmex_coverage']
            + (float) $nb['pending_and_advance'],
            2
        );
        $this->assertSame(round((float) $nb['total'], 2), $sum);
    }

    /** @test */
    public function bulk_row_exposes_net_breakdown_with_unfloored_total_and_invariant(): void
    {
        $this->makeRefrend([
            'amount_pending_from_previous' => 100.00,
            'advance_payment_amount'       => 50.00,
        ]);

        $row = $this->bulkRow();
        $nb  = $row['refrend']['net_breakdown'];

        $this->assertSame(
            ['beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage', 'pending_and_advance', 'total', 'nominal'],
            array_keys($nb)
        );
        $this->assertSame('150.00', $nb['pending_and_advance']);
        $this->assertSame('1850.00', $nb['total']);
        $this->assertSame(number_format($row['refrend']['total_to_pay'], 2, '.', ''), $nb['total']);
        $this->assertSame('1800.00', $nb['nominal']['beca']);
        $this->assertSame('200.00', $nb['nominal']['apoyo']);
        $this->assertInvariant($nb);
    }

    /** @test */
    public function bulk_row_total_is_not_floored_for_a_zero_payable_refrend(): void
    {
        $this->makeRefrend([
            'status'              => RefrendStatus::PAID->value,
            'final_amount'        => 0.00,
            'discount_percentage' => 100,
            'discount_amount'     => 2000.00,
        ]);

        $nb = $this->bulkRow()['refrend']['net_breakdown'];

        $this->assertSame('0.00', $nb['total']);
        foreach (['beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage', 'pending_and_advance'] as $k) {
            $this->assertSame('0.00', $nb[$k], $k);
        }
    }

    /** @test */
    public function bulk_row_keeps_existing_refrend_fields_unchanged(): void
    {
        $this->makeRefrend();
        $row = $this->bulkRow()['refrend'];

        $this->assertSame(1700.0, $row['total_to_pay']);
        $this->assertEquals(1700.0, (float) $row['final_amount']);
        $this->assertArrayHasKey('snapshot_monto_apoyo', $row);
    }

    /** @test */
    public function bulk_net_breakdown_does_not_scale_the_query_count(): void
    {
        $this->makeRefrend();
        $this->bulkRow();

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->bulkRow();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $one = $count();
        $this->makeRefrend();
        $this->makeRefrend();

        $this->assertSame($one, $count());
    }

    /** @test */
    public function model_accessor_is_not_serialized_by_default(): void
    {
        $refrend = $this->makeRefrend();

        $this->assertArrayNotHasKey('net_breakdown', $refrend->fresh()->toArray());
    }

    /** @test */
    public function model_accessor_returns_the_same_shape_over_total_to_pay(): void
    {
        $refrend = $this->makeRefrend(['amount_pending_from_previous' => 100.00])->fresh();

        $nb = $refrend->net_breakdown;

        $this->assertSame($refrend->total_to_pay, $nb['total']);
        $this->assertSame('100.00', $nb['pending_and_advance']);
        $this->assertInvariant($nb);
    }

    /** @test */
    public function historical_refrend_is_derived_on_read_and_holds_the_invariant(): void
    {
        $refrend = $this->makeRefrend([
            'status'       => RefrendStatus::PAID->value,
            'period_year'  => 2024,
            'period_month' => 1,
            'final_amount' => 1700.00,
        ])->fresh();

        $nb = $refrend->net_breakdown;

        $this->assertSame('1530.00', $nb['beca']);
        $this->assertSame('170.00', $nb['apoyo']);
        $this->assertInvariant($nb);
    }

    /** @test */
    public function for_user_endpoint_appends_net_breakdown_without_n_plus_one(): void
    {
        $admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $user  = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $this->makeRefrend(['period_month' => 1], $user);
        $this->makeRefrend(['period_month' => 2], $user);
        Sanctum::actingAs($admin);

        $count = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $res = $this->getJson("/api/admin/users/{$user->id}/scholarship-refrends");
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            $res->assertOk();
            foreach ($res->json('data') as $item) {
                $this->assertArrayHasKey('net_breakdown', $item);
                $this->assertSame($item['total_to_pay'], $item['net_breakdown']['total']);
            }

            return $n;
        };

        $two = $count();
        $this->makeRefrend(['period_month' => 3], $user);
        $this->assertSame($two, $count());
    }

    /** @test */
    public function other_endpoints_do_not_serialize_net_breakdown(): void
    {
        $admin   = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $refrend = $this->makeRefrend();
        Sanctum::actingAs($admin);

        $res = $this->getJson("/api/admin/scholarship-refrends/{$refrend->id}");

        $res->assertOk();
        $this->assertArrayNotHasKey('net_breakdown', $res->json('data'));
    }
}
