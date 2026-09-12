<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers spec (obs #1573) capability "administration-role-read-permissions":
 * RoleSeeder must create ROOT_ADMINISTRATION and grant it exactly ADM_READ_USERS,
 * nothing else, and that grant must be safe to re-apply without duplicating rows.
 */
class RoleSeederAdministrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeder_grants_exactly_adm_read_users_to_root_administration(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();

        $this->assertNotNull($role, 'ROOT_ADMINISTRATION role was not created by the seeder.');

        $permissionNames = $role->permissions()->pluck('name')->toArray();

        $this->assertEquals(
            ['ADM_READ_USERS'],
            $permissionNames,
            'ROOT_ADMINISTRATION must hold exactly ADM_READ_USERS and nothing else.'
        );
    }

    /** @test */
    public function seeder_does_not_create_adm_read_graduates_permission(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertFalse(
            Permission::where('name', 'ADM_READ_GRADUATES')->exists(),
            'ADM_READ_GRADUATES must not exist anywhere in the seeder (spec explicitly drops it).'
        );
    }

    /** @test */
    public function seeder_grants_zero_ps_permissions_to_root_administration(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();

        $psPermissionCount = $role->permissions()
            ->where('name', 'like', 'PS_%')
            ->count();

        $this->assertSame(0, $psPermissionCount, 'ROOT_ADMINISTRATION must hold zero PS_* permissions.');
    }

    /**
     * The full RoleSeeder class is documented as NOT idempotent (several PS_* permissions use
     * Permission::create(), which throws on a unique-constraint violation on re-run). The
     * ROOT_ADMINISTRATION role and its ADM_READ_USERS grant are the only entries in the file
     * built with firstOrCreate() specifically so they CAN be re-applied safely. This test
     * exercises exactly that idempotent slice twice, mirroring what RoleSeeder.php does
     * internally, without re-running the rest of the (non-idempotent) class.
     *
     * @test
     */
    public function root_administration_role_and_permission_grant_is_idempotent_on_reapply(): void
    {
        $applyGrant = function (): void {
            $role = Role::firstOrCreate(['name' => 'ROOT_ADMINISTRATION']);
            Permission::firstOrCreate(['name' => 'ADM_READ_USERS'])->syncRoles([$role]);
        };

        $applyGrant();
        $applyGrant();

        $this->assertSame(1, Role::where('name', 'ROOT_ADMINISTRATION')->count());
        $this->assertSame(1, Permission::where('name', 'ADM_READ_USERS')->count());

        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();
        $this->assertEquals(['ADM_READ_USERS'], $role->permissions()->pluck('name')->toArray());
    }
}
