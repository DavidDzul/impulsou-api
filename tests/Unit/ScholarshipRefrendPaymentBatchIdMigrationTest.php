<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers design D4 / tasks 1.3-1.4 (becario-payment-file-generation PR1):
 * nullable `payment_batch_id` FK on `scholarship_refrends`, kept in its own
 * migration file so it can be rolled back independently of the batches
 * table. NAMED class, same require_once + instantiate pattern as
 * ScholarshipPaymentBatchMigrationTest / PermissionDescriptionModuleMigrationTest.
 */
class ScholarshipRefrendPaymentBatchIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_09_13_140000_add_payment_batch_id_to_scholarship_refrends.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::MIGRATION_PATH;

        return new \AddPaymentBatchIdToScholarshipRefrends();
    }

    /** @test */
    public function migration_adds_nullable_payment_batch_id_column(): void
    {
        // Reset via the migration's own (sqlite-safe) down() rather than a
        // raw dropForeign call here, since Blueprint::dropForeign() is
        // unconditionally unsupported on SQLite (the test driver).
        $this->migration()->down();

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'payment_batch_id'));
    }

    /**
     * Relies on the already-migrated schema from RefreshDatabase's initial
     * `artisan migrate`. Uses a raw minimal insert (no factory exists for
     * ScholarshipRefrend) covering only the NOT NULL columns without
     * defaults on the base table.
     *
     * @test
     */
    public function column_is_nullable_and_existing_refrends_are_unaffected(): void
    {
        $user = User::factory()->create();

        $id = DB::table('scholarship_refrends')->insertGetId([
            'user_id'       => $user->id,
            'period_year'   => 2026,
            'period_month'  => 9,
            'base_amount'   => 100.00,
            'final_amount'  => 100.00,
            'snapshot_name' => 'Test User',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $row = DB::table('scholarship_refrends')->where('id', $id)->first();

        $this->assertNull($row->payment_batch_id);
    }

    /** @test */
    public function rollback_drops_the_column_and_leaves_the_batches_table_untouched(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('scholarship_refrends', 'payment_batch_id'));
        $this->assertTrue(Schema::hasTable('scholarship_payment_batches'));
    }
}
