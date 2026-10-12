<?php

use App\Enums\PaymentBatchType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/pagos-lote-pago-adelantado, design Part 1 — adds `batch_type` to
 * `scholarship_payment_batches`' identity, widening the unique key from
 * (campus, period_year, period_month) to
 * (campus, period_year, period_month, batch_type). A NORMAL batch and a
 * CERT_UNID batch may now coexist for the same campus/period.
 *
 * Named class (project style, per `2026_09_13_130000`/`2026_10_05_100000`),
 * so the companion migration test can `require_once` this file once and
 * instantiate the named class directly per test — mirrors
 * ScholarshipPaymentBatchKeyUniqueSwapMigrationTest's exact rationale (a
 * literal `(require MIGRATION_PATH)->up()` would fatal on class
 * re-declaration across test methods run in one process).
 *
 * ATOMICITY ordering (same precedent as 2026_10_05_100000): add the column,
 * then add the NEW unique index (a superset of the old one, so it can never
 * fail on existing data once the column has a backfilled default), THEN
 * drop the OLD unique index. The table always has a uniqueness guard at
 * every step. Guarded with `Schema::hasColumn`/index-existence checks so
 * the migration is safe to re-run.
 */
class AddBatchTypeToScholarshipPaymentBatches extends Migration
{
    private const OLD_UNIQUE = 'sc_payment_batches_key_unique';
    private const NEW_UNIQUE = 'sc_payment_batches_key_type_unique';

    public function up(): void
    {
        if (!Schema::hasColumn('scholarship_payment_batches', 'batch_type')) {
            Schema::table('scholarship_payment_batches', function (Blueprint $table) {
                $table->string('batch_type', 20)
                    ->default(PaymentBatchType::NORMAL->value)
                    ->after('period_month');
            });
        }

        if (!$this->indexExists('scholarship_payment_batches', self::NEW_UNIQUE)) {
            Schema::table('scholarship_payment_batches', function (Blueprint $table) {
                $table->unique(
                    ['campus', 'period_year', 'period_month', 'batch_type'],
                    self::NEW_UNIQUE
                );
            });
        }

        if ($this->indexExists('scholarship_payment_batches', self::OLD_UNIQUE)) {
            Schema::table('scholarship_payment_batches', function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }
    }

    public function down(): void
    {
        // Stricter refusal than the 2026_10_05 precedent (approved amendment,
        // sdd/pagos-lote-pago-adelantado/amendments #3): refuse while ANY
        // CERT_UNID row exists, not only when both types exist for one key.
        // Once `batch_type` is dropped, a CERT-UNID-only campus/period key
        // would silently become "the" batch for that key, and the restored
        // 3-column unique index would then 409 the normal payroll the next
        // time it is processed for that same key.
        $hasCertUnidRows = DB::table('scholarship_payment_batches')
            ->where('batch_type', PaymentBatchType::CERT_UNID->value)
            ->exists();

        if ($hasCertUnidRows) {
            throw new RuntimeException(
                'Cannot roll back the scholarship_payment_batches batch_type column: '
                . 'at least one CERT_UNID batch exists. Dropping batch_type would fold '
                . 'that batch into the shared campus+period key, and the restored '
                . '3-column unique index would then reject the next NORMAL batch for '
                . 'that same key. Resolve or archive CERT_UNID batches before rolling back.'
            );
        }

        // Drop the 4-column unique BEFORE the column it references.
        if ($this->indexExists('scholarship_payment_batches', self::NEW_UNIQUE)) {
            Schema::table('scholarship_payment_batches', function (Blueprint $table) {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        if (Schema::hasColumn('scholarship_payment_batches', 'batch_type')) {
            Schema::table('scholarship_payment_batches', function (Blueprint $table) {
                $table->dropColumn('batch_type');
            });
        }

        if (!$this->indexExists('scholarship_payment_batches', self::OLD_UNIQUE)) {
            Schema::table('scholarship_payment_batches', function (Blueprint $table) {
                $table->unique(
                    ['campus', 'period_year', 'period_month'],
                    self::OLD_UNIQUE
                );
            });
        }
    }

    /**
     * Driver-agnostic index-existence check via doctrine/dbal (installed —
     * same dependency 2026_10_04_100000's docblock already documents),
     * consistent across MySQL/SQLite so the Schema::hasColumn-style guards
     * above also cover indexes, per design's explicit instruction.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes($table);

        return array_key_exists(strtolower($indexName), $indexes);
    }
}
