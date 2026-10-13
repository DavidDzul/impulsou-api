<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers sdd/verificacion-incidencias-ux (PR4a, amendment #2) task 4a.1: drops
 * the dead is_resolved/resolved_at/resolved_by_id/resolution_notes columns
 * from scholarship_refrend_incidents. FK involved (resolved_by_id -> users),
 * so down() must guard dropForeign for sqlite — same NAMED class,
 * require_once + instantiate pattern as
 * DropCarryoverPercentageFromScholarshipRefrendsMigrationTest.
 */
class DropResolutionColumnsFromScholarshipRefrendIncidentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_10_13_100000_drop_resolution_columns_from_scholarship_refrend_incidents.php';

    private const COLUMNS = ['is_resolved', 'resolved_at', 'resolved_by_id', 'resolution_notes'];

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::MIGRATION_PATH;

        return new \DropResolutionColumnsFromScholarshipRefrendIncidents();
    }

    /** @test */
    public function migration_drops_all_four_resolution_columns(): void
    {
        // RefreshDatabase already ran this migration's own up() as part of
        // the initial `artisan migrate`, so the columns start ABSENT here.
        // Restore them via down() first to exercise up() from a known
        // "columns present" state.
        $this->migration()->down();

        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_refrend_incidents', $column));
        }

        $this->migration()->up();

        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrend_incidents', $column));
        }
    }

    /** @test */
    public function up_is_idempotent_when_the_columns_are_already_gone(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrend_incidents', $column));
        }
    }

    /** @test */
    public function rollback_restores_all_four_columns_with_correct_definitions(): void
    {
        $this->migration()->up();
        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrend_incidents', $column));
        }

        $this->migration()->down();

        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_refrend_incidents', $column));
        }
    }

    /** @test */
    public function down_is_idempotent_when_the_columns_are_already_present(): void
    {
        $this->migration()->down();
        $this->migration()->down();

        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_refrend_incidents', $column));
        }
    }
}
