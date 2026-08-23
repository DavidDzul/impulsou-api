<?php

namespace Tests\Unit;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `RefreshDatabase` always runs every migration against an empty schema, so
 * the backfill UPDATE inside
 * `2026_08_22_100001_add_discount_valid_from_to_scholarship_profiles_table.php`
 * (setting discount_valid_from = DATE(created_at) for rows that already had
 * an active discount) never actually runs against a populated table during
 * the normal suite (W3 from sdd-verify). This test simulates the
 * pre-migration state directly and re-runs that exact migration file's
 * up() to prove the backfill SQL itself is correct.
 */
class DiscountValidFromBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_08_22_100001_add_discount_valid_from_to_scholarship_profiles_table.php';

    /** @test */
    public function backfill_sets_discount_valid_from_to_the_date_of_created_at_for_rows_with_an_active_discount(): void
    {
        $user = User::factory()->create();

        // Simulate the pre-migration schema: drop the column that the
        // migration under test is responsible for adding + backfilling, then
        // insert a row exactly as it would have existed before this
        // migration ever ran — bypassing the model/ORM (which would
        // otherwise auto-manage timestamps) by inserting through the query
        // builder directly.
        Schema::table('scholarship_profiles', function ($table) {
            $table->dropColumn('discount_valid_from');
        });

        $createdAt = Carbon::parse('2025-01-15 10:00:00');

        $profileId = DB::table('scholarship_profiles')->insertGetId([
            'user_id'                    => $user->id,
            'scholarship_type'           => 'IU',
            'monthly_amount'             => 2000.00,
            'payment_start_date'         => $createdAt->toDateString(),
            'active_discount_percentage' => 10.00,
            'discount_valid_until'       => '2025-12-31',
            'created_at'                 => $createdAt,
            'updated_at'                 => $createdAt,
        ]);

        // Re-run the exact migration file under test (adds the column back +
        // runs the backfill UPDATE) against the now-populated table.
        (require self::MIGRATION_PATH)->up();

        $this->assertSame(
            $createdAt->toDateString(),
            DB::table('scholarship_profiles')->where('id', $profileId)->value('discount_valid_from')
        );
    }

    /** @test */
    public function backfill_does_not_touch_rows_without_an_active_discount(): void
    {
        $user = User::factory()->create();

        Schema::table('scholarship_profiles', function ($table) {
            $table->dropColumn('discount_valid_from');
        });

        $createdAt = Carbon::parse('2025-01-15 10:00:00');

        $profileId = DB::table('scholarship_profiles')->insertGetId([
            'user_id'                    => $user->id,
            'scholarship_type'           => 'IU',
            'monthly_amount'             => 2000.00,
            'payment_start_date'         => $createdAt->toDateString(),
            'active_discount_percentage' => null,
            'created_at'                 => $createdAt,
            'updated_at'                 => $createdAt,
        ]);

        (require self::MIGRATION_PATH)->up();

        $this->assertNull(
            DB::table('scholarship_profiles')->where('id', $profileId)->value('discount_valid_from')
        );
    }
}
