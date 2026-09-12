<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers spec R1: migration creates `scholarship_payment_data` without
 * touching `users`, enforces a unique(user_id) 1:1 constraint, and rolls
 * back cleanly. Uses the same require-the-migration-file pattern as
 * DiscountValidFromBackfillMigrationTest to re-run up()/down() directly
 * against the schema RefreshDatabase already built.
 */
class ScholarshipPaymentDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_09_11_000000_create_scholarship_payment_data_table.php';

    /** @test */
    public function migration_creates_table_with_expected_columns_and_leaves_users_untouched(): void
    {
        $usersColumnsBefore = Schema::getColumnListing('users');

        Schema::dropIfExists('scholarship_payment_data');

        (require self::MIGRATION_PATH)->up();

        $this->assertTrue(Schema::hasTable('scholarship_payment_data'));
        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'bank_name', 'account_number', 'curp', 'rfc', 'created_at', 'updated_at'],
            Schema::getColumnListing('scholarship_payment_data')
        );
        $this->assertSame($usersColumnsBefore, Schema::getColumnListing('users'));
    }

    /** @test */
    public function unique_constraint_on_user_id_rejects_a_second_row_for_the_same_user(): void
    {
        $user = User::factory()->create();

        DB::table('scholarship_payment_data')->insert([
            'user_id'        => $user->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => str_repeat('A', 18),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('scholarship_payment_data')->insert([
            'user_id'        => $user->id,
            'bank_name'      => 'Santander',
            'account_number' => '098765432109876543',
            'curp'           => str_repeat('B', 18),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /** @test */
    public function rollback_drops_the_table_and_leaves_users_untouched(): void
    {
        $usersColumnsBefore = Schema::getColumnListing('users');

        (require self::MIGRATION_PATH)->down();

        $this->assertFalse(Schema::hasTable('scholarship_payment_data'));
        $this->assertSame($usersColumnsBefore, Schema::getColumnListing('users'));
    }
}
