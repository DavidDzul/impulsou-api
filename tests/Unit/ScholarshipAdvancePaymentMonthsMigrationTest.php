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
 * Covers design "Schema / scholarship_advance_payment_months (child)" and
 * spec "Domain: scholarship-advance-payment / Requirement: Child table
 * uniqueness constraint" — tasks 2.2 + 2.5 (Work Unit 2 / PR2).
 *
 * The unique(['user_id','period_year','period_month']) constraint is the
 * double-claim guard: it must reject a second insert at the DB level, not
 * merely at the application layer, so two concurrent requests can never both
 * claim the same future month (spec scenario "Concurrent duplicate insert
 * fails at DB level").
 */
class ScholarshipAdvancePaymentMonthsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_09_24_000002_create_scholarship_advance_payment_months_table.php';

    private function makeRefrend(User $user, int $year, int $month, string $refrendType = 'NORMAL'): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'       => $user->id,
            'period_year'   => $year,
            'period_month'  => $month,
            'refrend_type'  => $refrendType,
            'base_amount'   => 2000,
            'final_amount'  => 2000,
            'snapshot_name' => $user->name ?? 'Test User',
        ]);
    }

    private function makeHeader(User $user, ScholarshipRefrend $originRefrend): int
    {
        return DB::table('scholarship_advance_payments')->insertGetId([
            'user_id'              => $user->id,
            'origin_refrend_id'    => $originRefrend->id,
            'origin_period_year'   => $originRefrend->period_year,
            'origin_period_month'  => $originRefrend->period_month,
            'months_count'         => 1,
            'total_amount'         => 2000.00,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    /** @test */
    public function migration_creates_table_with_expected_columns(): void
    {
        Schema::dropIfExists('scholarship_advance_payment_months');

        (require self::MIGRATION_PATH)->up();

        $this->assertTrue(Schema::hasTable('scholarship_advance_payment_months'));
        $this->assertEqualsCanonicalizing(
            [
                'id',
                'advance_payment_id',
                'user_id',
                'period_year',
                'period_month',
                'amount',
                'refrend_id',
                'status',
                'reached_at',
                'settled_resolution_type',
                'divergence_reason',
                'created_at',
                'updated_at',
            ],
            Schema::getColumnListing('scholarship_advance_payment_months')
        );
    }

    /**
     * The hard requirement: a real duplicate-insert attempt against the same
     * (user_id, period_year, period_month) must fail at the DB level, not
     * merely be blocked by application logic.
     *
     * @test
     */
    public function unique_constraint_rejects_a_second_claim_of_the_same_user_period(): void
    {
        $user          = User::factory()->create();
        $originRefrend = $this->makeRefrend($user, 2026, 9);
        $headerId      = $this->makeHeader($user, $originRefrend);
        $futureRefrend = $this->makeRefrend($user, 2027, 6);

        DB::table('scholarship_advance_payment_months')->insert([
            'advance_payment_id' => $headerId,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => 2000.00,
            'refrend_id'         => $futureRefrend->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // A second, independent header/refrend pair attempting to claim the
        // SAME (user_id, period_year, period_month) — simulates two
        // concurrent advance-payment requests racing for the same future
        // month. Uses a different refrend_type for the physical future
        // refrend row so scholarship_refrends' OWN
        // (user_id,period_year,period_month,refrend_type) constraint doesn't
        // interfere — this test isolates the child table's
        // (user_id,period_year,period_month) constraint specifically.
        $secondOriginRefrend = $this->makeRefrend($user, 2026, 10);
        $secondHeaderId      = $this->makeHeader($user, $secondOriginRefrend);
        $secondFutureRefrend = $this->makeRefrend($user, 2027, 6, 'RETENCION');

        $this->expectException(QueryException::class);

        DB::table('scholarship_advance_payment_months')->insert([
            'advance_payment_id' => $secondHeaderId,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => 2000.00,
            'refrend_id'         => $secondFutureRefrend->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** @test */
    public function unique_constraint_on_refrend_id_rejects_two_children_pointing_at_the_same_refrend(): void
    {
        $user          = User::factory()->create();
        $originRefrend = $this->makeRefrend($user, 2026, 9);
        $headerId      = $this->makeHeader($user, $originRefrend);
        $futureRefrend = $this->makeRefrend($user, 2027, 6);

        DB::table('scholarship_advance_payment_months')->insert([
            'advance_payment_id' => $headerId,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => 2000.00,
            'refrend_id'         => $futureRefrend->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->expectException(QueryException::class);

        // Different (user, period) pair so only the refrend_id uniqueness is
        // exercised, not the (user_id, period_year, period_month) one.
        DB::table('scholarship_advance_payment_months')->insert([
            'advance_payment_id' => $headerId,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 7,
            'amount'             => 2000.00,
            'refrend_id'         => $futureRefrend->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** @test */
    public function status_defaults_to_pending(): void
    {
        $user          = User::factory()->create();
        $originRefrend = $this->makeRefrend($user, 2026, 9);
        $headerId      = $this->makeHeader($user, $originRefrend);
        $futureRefrend = $this->makeRefrend($user, 2027, 6);

        $id = DB::table('scholarship_advance_payment_months')->insertGetId([
            'advance_payment_id' => $headerId,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => 2000.00,
            'refrend_id'         => $futureRefrend->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->assertSame('PENDING', DB::table('scholarship_advance_payment_months')->find($id)->status);
    }

    /** @test */
    public function rollback_drops_the_table_cleanly(): void
    {
        (require self::MIGRATION_PATH)->down();

        $this->assertFalse(Schema::hasTable('scholarship_advance_payment_months'));
    }
}
