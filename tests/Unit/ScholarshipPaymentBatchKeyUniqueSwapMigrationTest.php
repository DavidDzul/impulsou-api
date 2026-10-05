<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers sdd/pagos-batch-sede-totals Phase 1 (PR1): the migration that
 * narrows scholarship_payment_batches' unique key from
 * (generation_id, campus, period_year, period_month) to
 * (campus, period_year, period_month) — the DB-level counterpart of
 * dropping generation_id from the batch key everywhere else in this PR.
 *
 * Named class (project style, per `2026_09_13_130000`/`2026_10_04_100000`),
 * so we `require_once` the file once and instantiate the named class
 * directly per test, mirroring ScholarshipPaymentBatchMigrationTest's
 * rationale (a literal `(require MIGRATION_PATH)->up()` would fatal on
 * class re-declaration across test methods run in one process).
 */
class ScholarshipPaymentBatchKeyUniqueSwapMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_10_05_100000_swap_scholarship_payment_batches_key_unique.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::MIGRATION_PATH;

        return new \SwapScholarshipPaymentBatchesKeyUnique();
    }

    private function insertBatch(array $overrides, int $adminId): void
    {
        DB::table('scholarship_payment_batches')->insert(array_merge([
            'generation_id'   => 1,
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
     * up()'s pre-flight audit (design: "the exact silent-double-batch
     * catastrophe the ATOMICITY note warns about") — two rows sharing
     * campus+period but differing ONLY by generation_id (which the OLD
     * 4-column index allowed) must abort the migration BEFORE the old
     * index is touched.
     *
     * RefreshDatabase's own `artisan migrate` already ran this
     * migration's up() once (this file lives under database/migrations),
     * so the schema starts in the POST-swap (3-column unique) state. We
     * call down() first — safe here since no NULL-generation rows exist
     * yet — to put the schema back into the OLD 4-column-unique state the
     * pre-flight audit is meant to run against, mirroring how a real
     * rollback-then-forward-again deploy would behave.
     *
     * @test
     */
    public function up_throws_on_a_pre_existing_campus_period_duplicate_with_the_old_index_still_intact(): void
    {
        $admin = User::factory()->create();
        $this->migration()->down();

        $this->insertBatch(['generation_id' => 1], $admin->id);
        $this->insertBatch(['generation_id' => 2], $admin->id);

        $this->expectException(RuntimeException::class);

        $this->migration()->up();
    }

    /**
     * Confirms the pre-flight abort really does leave the OLD 4-column
     * index intact (not a half-applied state) — a third insert that only
     * collides on the OLD index (same generation_id+campus+period) must
     * still be rejected by the DB after the aborted up().
     *
     * @test
     */
    public function up_leaves_the_old_index_intact_after_aborting_on_a_duplicate(): void
    {
        $admin = User::factory()->create();
        $this->migration()->down();

        $this->insertBatch(['generation_id' => 1], $admin->id);
        $this->insertBatch(['generation_id' => 2], $admin->id);

        try {
            $this->migration()->up();
        } catch (RuntimeException $e) {
            // expected
        }

        $this->expectException(QueryException::class);

        $this->insertBatch(['generation_id' => 1], $admin->id);
    }

    /**
     * Clean DB (no collisions) migrates successfully and the new 3-column
     * unique index is actually enforced: a second batch sharing
     * campus+period but with a DIFFERENT generation_id (impossible to
     * reject under the old index) must now be rejected.
     *
     * @test
     */
    public function up_migrates_cleanly_and_the_new_three_column_unique_index_rejects_a_differing_generation_duplicate(): void
    {
        $admin = User::factory()->create();
        $this->migration()->down();

        $this->insertBatch(['generation_id' => 1], $admin->id);

        $this->migration()->up();

        $this->expectException(QueryException::class);

        $this->insertBatch(['generation_id' => 2], $admin->id);
    }

    /**
     * down()'s rollback guard (design's rollback-safety requirement): a
     * row with generation_id IS NULL must block the rollback, since the
     * restored 4-column unique index can never treat that NULL as
     * colliding with anything (MySQL does not treat NULLs as equal for
     * uniqueness), silently reopening the double-payment race. The
     * schema is already in the post-swap (3-column unique) state here —
     * RefreshDatabase's own migrate run already applied it.
     *
     * @test
     */
    public function down_throws_while_a_null_generation_batch_exists(): void
    {
        $admin = User::factory()->create();

        $this->insertBatch(['generation_id' => null, 'campus' => 'CANCUN'], $admin->id);

        $this->expectException(RuntimeException::class);

        $this->migration()->down();
    }

    /**
     * Once every row has a non-NULL generation_id, down() succeeds and
     * restores the original 4-column unique index — proven here by
     * showing two rows sharing campus+period but differing by
     * generation_id (legal under the restored 4-column index) are both
     * accepted again.
     *
     * @test
     */
    public function down_succeeds_once_no_null_generation_rows_remain_and_restores_the_four_column_unique(): void
    {
        $admin = User::factory()->create();

        $this->insertBatch(['generation_id' => 1], $admin->id);

        $this->migration()->down();

        // Legal again under the restored 4-column unique index.
        $this->insertBatch(['generation_id' => 2], $admin->id);

        $this->assertSame(
            2,
            DB::table('scholarship_payment_batches')
                ->where('campus', 'MERIDA')
                ->where('period_year', 2026)
                ->where('period_month', 9)
                ->count()
        );
    }
}
