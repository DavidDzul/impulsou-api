<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/limpieza-refrendos (A4): drops the 4 dead refrend columns and shrinks
 * the status / discount_type enums (MySQL only). The guard refuses to run when
 * any dead-column data, legacy status or legacy discount type would be lost.
 */
class DropDeadRefrendColumnsAndShrinkEnumsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../../database/migrations/2026_10_26_200000_drop_dead_refrend_columns_and_shrink_enums.php';
    private const DEAD_COLUMNS = ['pedagogia_resolved_by_id', 'pedagogia_resolved_at', 'notified_by_id', 'missing_subjects_snapshot'];

    private int $sequence = 0;

    private function migration(): Migration
    {
        return require self::MIGRATION_PATH;
    }

    /** RefreshDatabase already ran up(); restore the columns so up() starts from "present". */
    private function restoreColumns(): void
    {
        $this->migration()->down();
        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_refrends', $column), "{$column} should be restored");
        }
    }

    private function assertColumnsGone(): void
    {
        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrends', $column), "{$column} should be dropped");
        }
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

        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_refrends', $column), "{$column} must survive a tripped guard");
        }
        $this->assertEquals($before, DB::table('scholarship_refrends')->orderBy('id')->get()->all(), 'no row may change when the guard trips');
    }

    /** @test */
    public function up_drops_the_four_dead_columns_on_clean_data(): void
    {
        $this->restoreColumns();
        $this->makeRefrend();

        $this->migration()->up();

        $this->assertColumnsGone();
        $this->assertSame(1, DB::table('scholarship_refrends')->count());
    }

    /** @test */
    public function up_is_idempotent_when_the_columns_are_already_gone(): void
    {
        $this->restoreColumns();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertColumnsGone();
    }

    /** @test */
    public function guard_aborts_when_pedagogia_resolved_by_id_has_data(): void
    {
        $this->restoreColumns();
        $user = User::factory()->create();
        $id = $this->makeRefrend(['pedagogia_resolved_by_id' => $user->id]);

        $this->assertGuardTrips([$id]);
    }

    /** @test */
    public function guard_aborts_when_pedagogia_resolved_at_has_data(): void
    {
        $this->restoreColumns();
        $id = $this->makeRefrend(['pedagogia_resolved_at' => now()->toDateTimeString()]);

        $this->assertGuardTrips([$id]);
    }

    /** @test */
    public function guard_aborts_when_notified_by_id_has_data(): void
    {
        $this->restoreColumns();
        $user = User::factory()->create();
        $id = $this->makeRefrend(['notified_by_id' => $user->id]);

        $this->assertGuardTrips([$id]);
    }

    /** @test */
    public function guard_aborts_when_missing_subjects_snapshot_is_non_zero(): void
    {
        $this->restoreColumns();
        $this->makeRefrend();
        $id = $this->makeRefrend(['missing_subjects_snapshot' => 2]);

        $this->assertGuardTrips([$id]);
    }

    /** @test */
    public function guard_aborts_for_a_status_outside_the_four_values(): void
    {
        $this->restoreColumns();
        $id = $this->makeRefrend(['status' => 'AUTHORIZED']);

        $this->assertGuardTrips([$id]);
    }

    /** @test */
    public function guard_aborts_for_a_discount_type_outside_the_three_values_naming_the_refrend(): void
    {
        $this->restoreColumns();
        $id = $this->makeRefrend();
        DB::table('scholarship_refrend_discounts')->insert([
            'scholarship_refrend_id' => $id,
            'discount_type'          => 'OTRO',
            'created_at'             => now()->toDateTimeString(),
            'updated_at'             => now()->toDateTimeString(),
        ]);

        $this->assertGuardTrips([$id]);
        $this->assertSame(1, DB::table('scholarship_refrend_discounts')->count());
    }

    /** @test */
    public function catch_up_cancels_automatic_egreso_rows_before_dropping(): void
    {
        $this->restoreColumns();
        $egreso = $this->makeRefrend([
            'resolution_type' => 'EGRESO_RETICULA',
            'workflow_status' => 'CLOSED',
            'locked_at'       => now()->toDateTimeString(),
        ]);
        $normalClosed = $this->makeRefrend(['resolution_type' => 'BECA_MES', 'workflow_status' => 'CLOSED']);

        $this->migration()->up();

        $this->assertSame('CANCELLED', DB::table('scholarship_refrends')->where('id', $egreso)->value('status'));
        $this->assertSame('DRAFT', DB::table('scholarship_refrends')->where('id', $normalClosed)->value('status'));
        $this->assertColumnsGone();
    }

    /** @test */
    public function down_restores_the_columns_with_their_original_definition(): void
    {
        $this->assertColumnsGone();

        $this->migration()->down();

        $id = $this->makeRefrend();
        $row = DB::table('scholarship_refrends')->where('id', $id)->first();
        $this->assertNull($row->pedagogia_resolved_by_id);
        $this->assertNull($row->pedagogia_resolved_at);
        $this->assertNull($row->notified_by_id);
        $this->assertEquals(0, $row->missing_subjects_snapshot);
    }

    /** @test */
    public function down_runs_twice_without_error(): void
    {
        $this->migration()->down();
        $this->migration()->down();

        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_refrends', $column));
        }
    }

    /** @test */
    public function up_down_up_ends_with_the_same_schema_as_the_first_up(): void
    {
        $afterFirstUp = Schema::getColumnListing('scholarship_refrends');
        $this->makeRefrend();

        $this->migration()->down();
        $this->migration()->up();

        $this->assertEqualsCanonicalizing($afterFirstUp, Schema::getColumnListing('scholarship_refrends'));
        $this->assertColumnsGone();
        $this->assertSame(1, DB::table('scholarship_refrends')->count());
    }
}
