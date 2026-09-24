<?php

namespace Tests\Unit;

use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers design "Schema / scholarship_advance_payments (header)" and
 * tasks 2.1 (Work Unit 2 / PR2). Header table for one advance-payment batch.
 * Mirrors ScholarshipPaymentDataMigrationTest's require-the-migration-file
 * pattern (anonymous-class migration, same style as the most recent
 * precedent — 2026_09_11_000000_create_scholarship_payment_data_table.php).
 */
class ScholarshipAdvancePaymentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_09_24_000001_create_scholarship_advance_payments_table.php';

    private function makeRefrend(?User $user = null): ScholarshipRefrend
    {
        $user ??= User::factory()->create();

        return ScholarshipRefrend::create([
            'user_id'       => $user->id,
            'period_year'   => 2026,
            'period_month'  => 9,
            'base_amount'   => 2000,
            'final_amount'  => 2000,
            'snapshot_name' => $user->name ?? 'Test User',
        ]);
    }

    /** @test */
    public function migration_creates_table_with_expected_columns(): void
    {
        Schema::dropIfExists('scholarship_advance_payments');

        (require self::MIGRATION_PATH)->up();

        $this->assertTrue(Schema::hasTable('scholarship_advance_payments'));
        $this->assertEqualsCanonicalizing(
            [
                'id',
                'user_id',
                'origin_refrend_id',
                'origin_period_year',
                'origin_period_month',
                'months_count',
                'total_amount',
                'status',
                'cause',
                'notes',
                'created_by_id',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('scholarship_advance_payments')
        );
    }

    /**
     * D6/D5 rationale: one advance batch per origin refrend makes
     * MAX_ADVANCED_MONTHS unambiguous (no cumulative-cap ambiguity across
     * batches) — mirrors scholarship_withholdings.unique(origin_refrend_id).
     *
     * @test
     */
    public function unique_constraint_on_origin_refrend_id_rejects_a_second_batch_for_the_same_refrend(): void
    {
        $user    = User::factory()->create();
        $refrend = $this->makeRefrend($user);

        DB::table('scholarship_advance_payments')->insert([
            'user_id'              => $user->id,
            'origin_refrend_id'    => $refrend->id,
            'origin_period_year'   => 2026,
            'origin_period_month'  => 9,
            'months_count'         => 1,
            'total_amount'         => 2000.00,
            'status'               => 'ACTIVE',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('scholarship_advance_payments')->insert([
            'user_id'              => $user->id,
            'origin_refrend_id'    => $refrend->id,
            'origin_period_year'   => 2026,
            'origin_period_month'  => 9,
            'months_count'         => 1,
            'total_amount'         => 1000.00,
            'status'               => 'ACTIVE',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    /** @test */
    public function status_defaults_to_active(): void
    {
        $user    = User::factory()->create();
        $refrend = $this->makeRefrend($user);

        $id = DB::table('scholarship_advance_payments')->insertGetId([
            'user_id'              => $user->id,
            'origin_refrend_id'    => $refrend->id,
            'origin_period_year'   => 2026,
            'origin_period_month'  => 9,
            'months_count'         => 1,
            'total_amount'         => 2000.00,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->assertSame('ACTIVE', DB::table('scholarship_advance_payments')->find($id)->status);
    }

    /** @test */
    public function rollback_drops_the_table_cleanly(): void
    {
        (require self::MIGRATION_PATH)->down();

        $this->assertFalse(Schema::hasTable('scholarship_advance_payments'));
    }
}
