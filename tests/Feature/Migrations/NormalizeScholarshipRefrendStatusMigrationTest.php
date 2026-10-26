<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/limpieza-refrendos (A1): data-fix migration. Normalizes the legacy
 * refrend statuses and backfills the automatic egreso rows to CANCELLED.
 * Legacy values are inserted through DB::table because the PHP enum will not
 * keep those cases.
 */
class NormalizeScholarshipRefrendStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../../database/migrations/2026_10_26_100000_normalize_scholarship_refrend_status.php';

    private int $sequence = 0;

    private function migration(): Migration
    {
        return require self::MIGRATION_PATH;
    }

    private function makeRefrend(array $attributes = []): int
    {
        $user = User::factory()->create();
        $now = now()->subDays(3)->toDateTimeString();

        return DB::table('scholarship_refrends')->insertGetId(array_merge([
            'user_id'         => $user->id,
            'period_year'     => 2030,
            'period_month'    => (++$this->sequence % 12) + 1,
            'refrend_type'    => 'NORMAL',
            'status'          => 'DRAFT',
            'workflow_status' => 'DRAFT',
            'base_amount'     => 2000,
            'final_amount'    => 2000,
            'snapshot_name'   => 'Test',
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $attributes));
    }

    private function status(int $id): string
    {
        return DB::table('scholarship_refrends')->where('id', $id)->value('status');
    }

    private function egresoRow(array $overrides = []): int
    {
        return $this->makeRefrend(array_merge([
            'resolution_type' => 'EGRESO_RETICULA',
            'workflow_status' => 'CLOSED',
            'locked_at'       => now()->toDateTimeString(),
        ], $overrides));
    }

    /** @param array<int, int> $ids */
    private function assertGuardTrips(array $ids): void
    {
        $before = DB::table('scholarship_refrends')->orderBy('id')->get()->all();

        try {
            $this->migration()->up();
            $this->fail('Expected RuntimeException from the guard.');
        } catch (\RuntimeException $e) {
            foreach ($ids as $id) {
                $this->assertStringContainsString((string) $id, $e->getMessage());
            }
        }

        $this->assertEquals($before, DB::table('scholarship_refrends')->orderBy('id')->get()->all(), 'no row may change when a guard trips');
    }

    /** @test */
    public function legacy_rows_with_payment_evidence_become_paid(): void
    {
        $closed = $this->makeRefrend(['status' => 'AUTHORIZED', 'paid_payment_method' => 'CHEQUE', 'workflow_status' => 'CLOSED', 'locked_at' => now()->toDateTimeString()]);
        $open = $this->makeRefrend(['status' => 'PEDAGOGIA_REVIEW', 'paid_payment_method' => 'TRANSFER']);

        $this->migration()->up();

        $this->assertSame('PAID', $this->status($closed));
        $this->assertSame('PAID', $this->status($open));
    }

    /** @test */
    public function unlocked_legacy_row_resolved_as_retenida_becomes_withheld(): void
    {
        $id = $this->makeRefrend(['status' => 'AUTHORIZED', 'resolution_type' => 'RETENIDA', 'workflow_status' => 'LISTO_PARA_PAGO']);

        $this->migration()->up();

        $this->assertSame('WITHHELD', $this->status($id));
    }

    /** @test */
    public function other_unlocked_legacy_rows_become_draft(): void
    {
        $a = $this->makeRefrend(['status' => 'AUTHORIZED', 'workflow_status' => 'LISTO_PARA_PAGO']);
        $b = $this->makeRefrend(['status' => 'ATENCION_REVIEW']);
        $c = $this->makeRefrend(['status' => 'PEDAGOGIA_REVIEW', 'resolution_type' => 'BECA_MES']);

        $this->migration()->up();

        foreach ([$a, $b, $c] as $id) {
            $this->assertSame('DRAFT', $this->status($id));
        }
    }

    /** @test */
    public function automatic_egreso_rows_become_cancelled_and_nothing_else_is_touched(): void
    {
        $egreso = $this->egresoRow();
        $manualEgresado = $this->egresoRow(['status' => 'CANCELLED', 'resolution_type' => 'EGRESADO']);
        $normalClosed = $this->makeRefrend(['resolution_type' => 'BECA_MES', 'workflow_status' => 'CLOSED', 'locked_at' => now()->toDateTimeString()]);
        $plainDraft = $this->makeRefrend();

        $this->migration()->up();

        $this->assertSame('CANCELLED', $this->status($egreso));
        $this->assertSame('CANCELLED', $this->status($manualEgresado));
        $this->assertSame('DRAFT', $this->status($normalClosed));
        $this->assertSame('DRAFT', $this->status($plainDraft));
    }

    /** @test */
    public function backfilled_rows_keep_their_updated_at(): void
    {
        $egreso = $this->egresoRow();
        $legacy = $this->makeRefrend(['status' => 'AUTHORIZED']);
        $ids = [$egreso, $legacy];
        $before = DB::table('scholarship_refrends')->whereIn('id', $ids)->pluck('updated_at', 'id')->all();

        $this->migration()->up();

        $this->assertEquals($before, DB::table('scholarship_refrends')->whereIn('id', $ids)->pluck('updated_at', 'id')->all());
    }

    /** @test */
    public function running_twice_changes_nothing_the_second_time(): void
    {
        $this->makeRefrend(['status' => 'AUTHORIZED', 'paid_payment_method' => 'CHEQUE']);
        $this->makeRefrend(['status' => 'ATENCION_REVIEW']);
        $this->egresoRow();

        $this->migration()->up();
        $snapshot = DB::table('scholarship_refrends')->orderBy('id')->get()->all();
        $this->migration()->up();

        $this->assertEquals($snapshot, DB::table('scholarship_refrends')->orderBy('id')->get()->all());
    }

    /** @test */
    public function clean_data_passes_silently_and_down_is_a_no_op(): void
    {
        $this->makeRefrend();
        $this->makeRefrend(['status' => 'PAID']);
        $before = DB::table('scholarship_refrends')->orderBy('id')->get()->all();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertEquals($before, DB::table('scholarship_refrends')->orderBy('id')->get()->all());
    }

    /** @test */
    public function guard_a_aborts_for_unpaid_legacy_rows_that_are_closed_or_locked(): void
    {
        $closed = $this->makeRefrend(['status' => 'AUTHORIZED', 'workflow_status' => 'CLOSED']);
        $locked = $this->makeRefrend(['status' => 'PEDAGOGIA_REVIEW', 'locked_at' => now()->toDateTimeString()]);
        $this->makeRefrend(['status' => 'ATENCION_REVIEW']);

        $this->assertGuardTrips([$closed, $locked]);
    }

    /** @test */
    public function guard_b_aborts_for_workflow_status_outside_the_four_values(): void
    {
        $id = $this->makeRefrend(['workflow_status' => 'PAID']);

        $this->assertGuardTrips([$id]);
    }

    /** @test */
    public function guard_c_aborts_for_removed_discount_types_naming_the_refrend(): void
    {
        $id = $this->makeRefrend();
        $this->makeRefrend(['status' => 'ATENCION_REVIEW']);
        DB::table('scholarship_refrend_discounts')->insert([
            'scholarship_refrend_id' => $id,
            'discount_type'          => 'OTRO',
            'created_at'             => now()->toDateTimeString(),
            'updated_at'             => now()->toDateTimeString(),
        ]);

        $this->assertGuardTrips([$id]);
    }

    /** @test */
    public function guard_d_aborts_for_egreso_rows_with_an_unexpected_status_or_a_batch(): void
    {
        $legacyStatus = $this->egresoRow(['status' => 'AUTHORIZED']);
        $paid = $this->egresoRow(['status' => 'PAID']);

        $this->assertGuardTrips([$legacyStatus, $paid]);
    }
}
