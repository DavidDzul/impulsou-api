<?php

namespace Tests\Feature\Migrations;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/cert-unid-historial PR3 (spec R9): scholarship_profiles.advance_payment_eligible
 * is renamed to is_cert_unid, keeping its data and its NOT NULL DEFAULT 0 definition.
 */
class RenameIsCertUnidOnScholarshipProfilesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../../database/migrations/2026_10_28_300000_rename_advance_payment_eligible_to_is_cert_unid_on_scholarship_profiles.php';

    private function migration(): Migration
    {
        return require self::MIGRATION_PATH;
    }

    /** @test */
    public function the_column_is_renamed(): void
    {
        $this->assertTrue(Schema::hasColumn('scholarship_profiles', 'is_cert_unid'));
        $this->assertFalse(Schema::hasColumn('scholarship_profiles', 'advance_payment_eligible'));
    }

    /** @test */
    public function a_row_inserted_without_a_value_defaults_to_zero(): void
    {
        $user = User::factory()->create();
        $now  = now()->toDateTimeString();

        DB::table('scholarship_profiles')->insert([
            'user_id'    => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(0, (int) DB::table('scholarship_profiles')->where('user_id', $user->id)->value('is_cert_unid'));
    }

    /** @test */
    public function down_then_up_preserves_the_data(): void
    {
        $on  = User::factory()->create();
        $off = User::factory()->create();
        ScholarshipProfile::factory()->create(['user_id' => $on->id, 'is_cert_unid' => true]);
        ScholarshipProfile::factory()->create(['user_id' => $off->id, 'is_cert_unid' => false]);

        $migration = $this->migration();
        $migration->down();

        $this->assertTrue(Schema::hasColumn('scholarship_profiles', 'advance_payment_eligible'));
        $this->assertFalse(Schema::hasColumn('scholarship_profiles', 'is_cert_unid'));
        $this->assertSame(1, (int) DB::table('scholarship_profiles')->where('user_id', $on->id)->value('advance_payment_eligible'));

        $migration->up();

        $this->assertSame(1, (int) DB::table('scholarship_profiles')->where('user_id', $on->id)->value('is_cert_unid'));
        $this->assertSame(0, (int) DB::table('scholarship_profiles')->where('user_id', $off->id)->value('is_cert_unid'));
    }
}
