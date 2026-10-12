<?php

namespace Tests\Unit;

use App\Enums\PaymentBatchType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers sdd/pagos-lote-pago-adelantado Phase 1 (PR1): the migration that
 * widens scholarship_payment_batches' unique key from
 * (campus, period_year, period_month) to
 * (campus, period_year, period_month, batch_type).
 *
 * Named class, `require_once` + direct instantiation per test — same
 * rationale as ScholarshipPaymentBatchKeyUniqueSwapMigrationTest (a literal
 * `(require MIGRATION_PATH)->up()` would fatal on class re-declaration
 * across test methods run in one process).
 */
class AddBatchTypeToScholarshipPaymentBatchesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_10_12_100000_add_batch_type_to_scholarship_payment_batches.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::MIGRATION_PATH;

        return new \AddBatchTypeToScholarshipPaymentBatches();
    }

    private function insertBatch(array $overrides, int $adminId): void
    {
        DB::table('scholarship_payment_batches')->insert(array_merge([
            'campus'          => 'MERIDA',
            'period_year'     => 2026,
            'period_month'    => 9,
            'refrend_count'   => 1,
            'total_amount'    => 1000.00,
            'processed_by_id' => $adminId,
            'processed_at'    => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));
    }

    /**
     * RefreshDatabase's own `artisan migrate` already ran this migration's
     * up() once (this file lives under database/migrations), so the schema
     * starts in the POST-change (4-column unique, batch_type present) state.
     * We call down() first to put the schema back into the OLD (3-column
     * unique, no batch_type) state these tests exercise — same convention as
     * ScholarshipPaymentBatchKeyUniqueSwapMigrationTest.
     *
     * @test
     */
    public function up_backfills_pre_existing_rows_to_normal(): void
    {
        $admin = User::factory()->create();
        $this->migration()->down();
        $this->insertBatch([], $admin->id);

        $this->migration()->up();

        $this->assertSame(
            PaymentBatchType::NORMAL->value,
            DB::table('scholarship_payment_batches')->value('batch_type')
        );
    }

    /**
     * The core DB-level guarantee behind the spec scenario "Both types
     * processed for the same campus/month": the new 4-column unique index
     * must allow a NORMAL and a CERT_UNID row to coexist for the same
     * campus+period.
     *
     * @test
     */
    public function up_migrates_cleanly_and_the_new_unique_index_allows_both_types_for_the_same_campus_and_period(): void
    {
        $admin = User::factory()->create();
        $this->migration()->down();
        // Legacy row, inserted BEFORE batch_type exists on the schema.
        $this->insertBatch([], $admin->id);

        $this->migration()->up();

        // Must NOT throw — a CERT_UNID row for the SAME campus+period is
        // legal under the new 4-column unique key.
        $this->insertBatch(['batch_type' => PaymentBatchType::CERT_UNID->value], $admin->id);

        $this->assertSame(
            2,
            DB::table('scholarship_payment_batches')
                ->where('campus', 'MERIDA')
                ->where('period_year', 2026)
                ->where('period_month', 9)
                ->count()
        );
    }

    /**
     * The new 4-column unique index still rejects a TRUE duplicate — same
     * campus+period+batch_type twice.
     *
     * @test
     */
    public function up_new_unique_index_still_rejects_a_same_type_duplicate(): void
    {
        $admin = User::factory()->create();
        $this->migration()->down();
        // Legacy row, inserted BEFORE batch_type exists on the schema.
        $this->insertBatch([], $admin->id);

        $this->migration()->up();

        $this->expectException(QueryException::class);

        $this->insertBatch(['batch_type' => PaymentBatchType::NORMAL->value], $admin->id);
    }

    /**
     * down()'s rollback guard (approved stricter amendment,
     * sdd/pagos-lote-pago-adelantado/amendments #3): refuse while ANY
     * CERT_UNID row exists anywhere, not only when both types exist for one
     * key.
     *
     * @test
     */
    public function down_throws_while_any_cert_unid_batch_exists(): void
    {
        $admin = User::factory()->create();

        $this->insertBatch(['batch_type' => PaymentBatchType::CERT_UNID->value], $admin->id);

        $this->expectException(RuntimeException::class);

        $this->migration()->down();
    }

    /**
     * Once no CERT_UNID rows remain, down() succeeds: drops batch_type and
     * restores the original 3-column unique index.
     *
     * @test
     */
    public function down_succeeds_once_no_cert_unid_rows_remain_and_restores_the_three_column_unique(): void
    {
        $admin = User::factory()->create();
        $this->insertBatch(['batch_type' => PaymentBatchType::NORMAL->value], $admin->id);

        $this->migration()->down();

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('scholarship_payment_batches', 'batch_type')
        );

        // The restored 3-column unique index rejects a second row for the
        // same campus+period regardless of (now-dropped) type.
        $this->expectException(QueryException::class);

        $this->insertBatch([], $admin->id);
    }
}
