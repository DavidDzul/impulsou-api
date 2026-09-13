<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers spec requirement "Nullable metadata columns on `permissions`":
 * migration adds nullable `description`(255)/`module`(100) to `permissions`
 * only, with no default and no NOT NULL constraint, and rolls back cleanly.
 * This migration is a NAMED class (per design, mirroring the
 * AddTypeColumnsToRolesAndPermissionsTable precedent) rather than the
 * anonymous-class style ScholarshipPaymentDataMigrationTest/
 * DiscountValidFromBackfillMigrationTest wrap. A literal
 * `(require self::MIGRATION_PATH)->up()` repeated across test methods would
 * fatal on the second call ("Cannot declare class ... already in use") since
 * PHPUnit runs this file's methods in one process and `require` re-declares
 * the named class every call. We `require_once` the file once and instantiate
 * the named class directly per test instead, which is equivalent in effect.
 */
class PermissionDescriptionModuleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_09_12_120000_add_description_and_module_to_permissions_table.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::MIGRATION_PATH;

        return new \AddDescriptionAndModuleToPermissionsTable();
    }

    /** @test */
    public function migration_adds_nullable_columns_without_touching_roles(): void
    {
        $rolesColumnsBefore = Schema::getColumnListing('roles');

        Schema::table('permissions', function ($table) {
            $table->dropColumn(['description', 'module']);
        });

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('permissions', 'description'));
        $this->assertTrue(Schema::hasColumn('permissions', 'module'));
        $this->assertSame($rolesColumnsBefore, Schema::getColumnListing('roles'));
    }

    /**
     * The migration file lives in the real `database/migrations` directory,
     * so RefreshDatabase's initial full `artisan migrate` already applies it
     * once before any test runs — this test relies on that already-migrated
     * schema (mirrors ScholarshipPaymentDataMigrationTest's
     * unique_constraint_on_user_id test, which never re-calls up() either).
     *
     * @test
     */
    public function columns_are_nullable_and_accept_a_permission_row_with_neither_field_set(): void
    {
        $permission = Permission::create(['name' => 'TEST_NO_COPY_PERMISSION']);

        $this->assertNull($permission->fresh()->description);
        $this->assertNull($permission->fresh()->module);
    }

    /**
     * Relies on the already-migrated schema (see note above) and only
     * exercises down(), same as ScholarshipPaymentDataMigrationTest's own
     * rollback test.
     *
     * @test
     */
    public function rollback_drops_both_columns_and_leaves_roles_untouched(): void
    {
        $rolesColumnsBefore = Schema::getColumnListing('roles');

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('permissions', 'description'));
        $this->assertFalse(Schema::hasColumn('permissions', 'module'));
        $this->assertSame($rolesColumnsBefore, Schema::getColumnListing('roles'));
    }
}
