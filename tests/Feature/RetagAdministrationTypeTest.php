<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers spec (obs #1592) R1 — B0: retag ROOT_ADMINISTRATION and its 3 ADM_*
 * permissions to type='ADMINISTRATION'.
 *
 * Design (obs #1593) chose a data-only retag via RoleSeeder using
 * updateOrCreate() (not a dedicated migration) — see PR1 apply-progress for
 * the documented deviation rationale. updateOrCreate() matches purely by
 * `name` and forces `type` on BOTH create and update, so it correctly
 * retags rows that already exist with type='USER' (the column default,
 * added by migration 2026_01_04_111553) from prior seeding, while remaining
 * safe to re-run (idempotent — no duplicate rows).
 */
class RetagAdministrationTypeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeding_onto_fresh_db_tags_root_administration_as_administration_type(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();

        $this->assertNotNull($role);
        $this->assertSame('ADMINISTRATION', $role->type);
    }

    /** @test */
    public function seeding_onto_fresh_db_tags_the_three_adm_permissions_as_administration_type(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (['ADM_READ_USERS', 'ADM_READ_PAYMENT_DATA', 'ADM_EDIT_PAYMENT_DATA'] as $permissionName) {
            $permission = Permission::where('name', $permissionName)->first();

            $this->assertNotNull($permission, "{$permissionName} was not created by the seeder.");
            $this->assertSame('ADMINISTRATION', $permission->type, "{$permissionName} must be type='ADMINISTRATION'.");
        }
    }

    /**
     * Simulates the real-world scenario this PR must handle: a DB already
     * seeded by a PRIOR version of RoleSeeder (before this change), where
     * ROOT_ADMINISTRATION and the 3 ADM_* permissions exist with the
     * column's default type='USER'. Re-running the (updated) seeder must
     * retag them to 'ADMINISTRATION' — firstOrCreate() alone could NOT do
     * this, since it only sets attributes on CREATE, never on an existing
     * match.
     *
     * @test
     */
    public function reseeding_an_already_populated_db_retags_existing_rows_to_administration_type(): void
    {
        // Arrange: pre-existing rows with the column default type='USER',
        // mirroring a DB seeded before this change shipped. Re-fetch fresh
        // from the DB after create() — Eloquent does not sync DB-level
        // column defaults into the in-memory model returned by create().
        Role::create(['name' => 'ROOT_ADMINISTRATION']);
        $this->assertSame('USER', Role::where('name', 'ROOT_ADMINISTRATION')->first()->type);

        foreach (['ADM_READ_USERS', 'ADM_READ_PAYMENT_DATA', 'ADM_EDIT_PAYMENT_DATA'] as $permissionName) {
            Permission::create(['name' => $permissionName]);
            $this->assertSame('USER', Permission::where('name', $permissionName)->first()->type);
        }

        // Act: re-run the seeder (as would happen re-deploying this change
        // onto an already-populated environment).
        $this->seed(RoleSeeder::class);

        // Assert: retagged in place, no duplicate rows created.
        $this->assertSame(1, Role::where('name', 'ROOT_ADMINISTRATION')->count());
        $this->assertSame('ADMINISTRATION', Role::where('name', 'ROOT_ADMINISTRATION')->first()->type);

        foreach (['ADM_READ_USERS', 'ADM_READ_PAYMENT_DATA', 'ADM_EDIT_PAYMENT_DATA'] as $permissionName) {
            $this->assertSame(1, Permission::where('name', $permissionName)->count());
            $this->assertSame('ADMINISTRATION', Permission::where('name', $permissionName)->first()->type);
        }
    }

    /**
     * Re-applying just the retagged B0 lines twice in a row (the
     * idempotency scenario from spec R1) must not error and must not
     * create duplicates or drift the type away from 'ADMINISTRATION'.
     *
     * NOTE: this exercises the idempotent slice directly (mirroring
     * RoleSeederAdministrationTest's precedent), not a full re-run of
     * RoleSeeder::class — the rest of that seeder class is documented as
     * NOT idempotent (several PS_* permissions use plain create(), which
     * throws RoleAlreadyExists/unique-constraint errors on re-run).
     *
     * @test
     */
    public function retag_is_idempotent_across_repeated_seeder_runs(): void
    {
        $applyRetag = function (): void {
            $role = Role::updateOrCreate(['name' => 'ROOT_ADMINISTRATION'], ['type' => 'ADMINISTRATION']);
            Permission::updateOrCreate(['name' => 'ADM_READ_USERS'], ['type' => 'ADMINISTRATION'])->syncRoles([$role]);
            Permission::updateOrCreate(['name' => 'ADM_READ_PAYMENT_DATA'], ['type' => 'ADMINISTRATION'])->syncRoles([$role]);
            Permission::updateOrCreate(['name' => 'ADM_EDIT_PAYMENT_DATA'], ['type' => 'ADMINISTRATION'])->syncRoles([$role]);
        };

        $applyRetag();
        $applyRetag();

        $this->assertSame(1, Role::where('name', 'ROOT_ADMINISTRATION')->count());
        $this->assertSame('ADMINISTRATION', Role::where('name', 'ROOT_ADMINISTRATION')->first()->type);

        foreach (['ADM_READ_USERS', 'ADM_READ_PAYMENT_DATA', 'ADM_EDIT_PAYMENT_DATA'] as $permissionName) {
            $this->assertSame(1, Permission::where('name', $permissionName)->count());
            $this->assertSame('ADMINISTRATION', Permission::where('name', $permissionName)->first()->type);
        }
    }

    /**
     * Spec R1 scenario "Retagging does not alter any PS_* permission or any
     * other role's type" — spot-checks ROOT, ROOT_CAMPUS, a PS_* permission,
     * and a business-tier role (DIAMOND), all of which must remain
     * type='USER' (the column default) after the retag.
     *
     * @test
     */
    public function retag_does_not_change_type_of_other_roles_or_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $root = Role::where('name', 'ROOT')->first();
        $rootCampus = Role::where('name', 'ROOT_CAMPUS')->first();
        $diamond = Role::where('name', 'DIAMOND')->first();
        $psPermission = Permission::where('name', 'PS_READ_USERS')->first();

        $this->assertNotNull($root);
        $this->assertNotNull($rootCampus);
        $this->assertNotNull($diamond);
        $this->assertNotNull($psPermission);

        $this->assertSame('USER', $root->type, 'ROOT must remain type=USER.');
        $this->assertSame('USER', $rootCampus->type, 'ROOT_CAMPUS must remain type=USER.');
        $this->assertSame('USER', $diamond->type, 'DIAMOND must remain type=USER.');
        $this->assertSame('USER', $psPermission->type, 'PS_READ_USERS must remain type=USER.');
    }
}
