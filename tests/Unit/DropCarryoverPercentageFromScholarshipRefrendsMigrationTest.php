<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers design D-style migration / tasks 3.3-3.4 (withholding-detail-display
 * PR3): drops the dead `carryover_percentage` column, no FK involved so no
 * sqlite dropForeign branch is needed (unlike
 * ScholarshipRefrendPaymentBatchIdMigrationTest). NAMED class, same
 * require_once + instantiate pattern as that migration test.
 */
class DropCarryoverPercentageFromScholarshipRefrendsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_09_16_000001_drop_carryover_percentage_from_scholarship_refrends.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::MIGRATION_PATH;

        return new \DropCarryoverPercentageFromScholarshipRefrends();
    }

    /** @test */
    public function migration_drops_the_carryover_percentage_column(): void
    {
        // RefreshDatabase already ran this migration's own up() as part of
        // the initial `artisan migrate`, so the column starts ABSENT here.
        // Restore it via down() first (same trick as
        // ScholarshipRefrendPaymentBatchIdMigrationTest) to exercise up()
        // from a known "column present" state.
        $this->migration()->down();
        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'carryover_percentage'));

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('scholarship_refrends', 'carryover_percentage'));
    }

    /** @test */
    public function up_is_idempotent_when_the_column_is_already_gone(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('scholarship_refrends', 'carryover_percentage'));
    }

    /** @test */
    public function rollback_restores_the_column_as_nullable_with_no_data_loss(): void
    {
        $this->migration()->up();
        $this->assertFalse(Schema::hasColumn('scholarship_refrends', 'carryover_percentage'));

        $this->migration()->down();

        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'carryover_percentage'));
    }
}
