<?php

namespace Tests\Unit;

use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/egresado-status-timing, design D4 + risk R5 (task 4.6, Phase 1.1/1.2).
 *
 * RefreshDatabase already runs this migration's own up() as part of the
 * initial `artisan migrate`, so `performed_by_id` starts NULLABLE here —
 * same "restore via down() first" trick as
 * DropCarryoverPercentageFromScholarshipRefrendsMigrationTest, to exercise
 * up() from a known NOT NULL starting state and down() from a known
 * nullable state with a NULL row actually present.
 *
 * `scholarship_refrend_id` is NOT NULL + FK-constrained and untouched by
 * this migration, so every row created here carries a real refrend to
 * satisfy that constraint independently of the column under test.
 */
class MakePerformedByIdNullableOnScholarshipRefrendLogsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_10_04_100000_make_performed_by_id_nullable_on_scholarship_refrend_logs_table.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::MIGRATION_PATH;

        return new \MakePerformedByIdNullableOnScholarshipRefrendLogsTable();
    }

    private function makeRefrend(): ScholarshipRefrend
    {
        $user = User::factory()->create();

        return ScholarshipRefrend::create([
            'user_id'       => $user->id,
            'period_year'   => 2026,
            'period_month'  => 9,
            'base_amount'   => 1000,
            'final_amount'  => 1000,
            'snapshot_name' => 'Test Becario',
        ]);
    }

    /** @test */
    public function up_makes_the_column_nullable_and_a_null_row_can_be_inserted(): void
    {
        // Restore NOT NULL first so up() is exercised from the pre-migration
        // state, not a no-op.
        $this->migration()->down();

        $this->migration()->up();

        $refrend = $this->makeRefrend();

        // No constraint violation inserting a NULL performed_by_id — the
        // behavior the automatic egreso path depends on.
        $id = DB::table('scholarship_refrend_logs')->insertGetId([
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'graduated',
            'performed_by_id'        => null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $this->assertNotNull($id);
        $this->assertNull(ScholarshipRefrendLog::find($id)->performed_by_id);
    }

    /** @test */
    public function down_deletes_null_rows_before_restoring_not_null(): void
    {
        // Ensure nullable (idempotent — already the post-migrate state).
        $this->migration()->up();

        $refrend   = $this->makeRefrend();
        $nullRowId = DB::table('scholarship_refrend_logs')->insertGetId([
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'graduated',
            'performed_by_id'        => null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $this->migration()->down();

        // The NULL row must have been deleted — not left behind to violate
        // the restored NOT NULL constraint.
        $this->assertNull(DB::table('scholarship_refrend_logs')->where('id', $nullRowId)->first());

        // NOT NULL is actually enforced again.
        $this->expectException(QueryException::class);
        DB::table('scholarship_refrend_logs')->insert([
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'graduated',
            'performed_by_id'        => null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /** @test */
    public function down_preserves_non_null_rows(): void
    {
        $this->migration()->up();

        $refrend      = $this->makeRefrend();
        $attributedBy = User::factory()->create();

        $attributedId = DB::table('scholarship_refrend_logs')->insertGetId([
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'graduated',
            'performed_by_id'        => $attributedBy->id,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        // A NULL-performed_by row that down() must remove.
        DB::table('scholarship_refrend_logs')->insert([
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'graduated',
            'performed_by_id'        => null,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $this->migration()->down();

        $this->assertNotNull(DB::table('scholarship_refrend_logs')->where('id', $attributedId)->first());
        $this->assertSame(1, DB::table('scholarship_refrend_logs')->count());
    }

    /** @test */
    public function foreign_key_to_users_is_preserved_after_up(): void
    {
        $this->migration()->up();

        $refrend = $this->makeRefrend();
        $user    = User::factory()->create();

        $log = ScholarshipRefrendLog::create([
            'scholarship_refrend_id' => $refrend->id,
            'action'                 => 'graduated',
            'performed_by_id'        => $user->id,
        ]);

        $this->assertSame($user->id, $log->performedBy->id);
    }
}
