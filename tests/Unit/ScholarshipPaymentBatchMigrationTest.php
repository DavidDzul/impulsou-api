<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers design D4 / tasks 1.1-1.2 (becario-payment-file-generation PR1):
 * `scholarship_payment_batches` is a persisted entity (not a derived query),
 * with a composite unique key on (generation_id, campus, period_year,
 * period_month) that makes double-payment a DB-level impossibility.
 *
 * This migration is a NAMED class per this batch's explicit style precedent
 * (`2026_09_12_120000_add_description_and_module_to_permissions_table.php`),
 * so we `require_once` the file once and instantiate the named class
 * directly per test — mirrors PermissionDescriptionModuleMigrationTest's
 * rationale (a literal `(require MIGRATION_PATH)->up()` would fatal on
 * class re-declaration across test methods run in one process).
 */
class ScholarshipPaymentBatchMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_09_13_130000_create_scholarship_payment_batches_table.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::MIGRATION_PATH;

        return new \CreateScholarshipPaymentBatchesTable();
    }

    /** @test */
    public function migration_creates_table_with_expected_columns(): void
    {
        Schema::dropIfExists('scholarship_payment_batches');

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('scholarship_payment_batches'));
        $this->assertEqualsCanonicalizing(
            [
                'id',
                'generation_id',
                'campus',
                'period_year',
                'period_month',
                'refrend_count',
                'total_amount',
                'processed_by_id',
                'processed_at',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('scholarship_payment_batches')
        );
    }

    /**
     * Relies on the already-migrated schema from RefreshDatabase's initial
     * `artisan migrate` (mirrors ScholarshipPaymentDataMigrationTest's
     * unique-constraint test, which never re-calls up()).
     *
     * @test
     */
    public function unique_constraint_rejects_a_duplicate_batch_key(): void
    {
        $admin = User::factory()->create();

        DB::table('scholarship_payment_batches')->insert([
            'generation_id'   => 1,
            'campus'          => 'CDMX',
            'period_year'     => 2026,
            'period_month'    => 9,
            'refrend_count'   => 5,
            'total_amount'    => 1000.00,
            'processed_by_id' => $admin->id,
            'processed_at'    => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('scholarship_payment_batches')->insert([
            'generation_id'   => 1,
            'campus'          => 'CDMX',
            'period_year'     => 2026,
            'period_month'    => 9,
            'refrend_count'   => 3,
            'total_amount'    => 500.00,
            'processed_by_id' => $admin->id,
            'processed_at'    => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /** @test */
    public function rollback_drops_the_table_cleanly(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('scholarship_payment_batches'));
    }
}
