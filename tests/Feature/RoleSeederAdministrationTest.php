<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers spec (obs #1573) capability "administration-role-read-permissions":
 * RoleSeeder must create ROOT_ADMINISTRATION and grant it ADM_READ_USERS,
 * and that grant must be safe to re-apply without duplicating rows.
 *
 * Extended by becarios-payment-config PR1b (design D5/D9, obs #1583):
 * ROOT_ADMINISTRATION also gets ADM_READ_PAYMENT_DATA/ADM_EDIT_PAYMENT_DATA,
 * granted the same firstOrCreate()+syncRoles() way for the same re-apply
 * safety guarantee.
 */
class RoleSeederAdministrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeder_grants_adm_read_users_to_root_administration(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();

        $this->assertNotNull($role, 'ROOT_ADMINISTRATION role was not created by the seeder.');

        $this->assertContains('ADM_READ_USERS', $role->permissions()->pluck('name')->toArray());
    }

    /**
     * Updated by control-accesos-administration-panel PR2 (design obs #1593,
     * spec obs #1592 R5): ROOT_ADMINISTRATION now also holds the 4 new
     * Control permissions (ADM_READ_ROLES, ADM_MANAGE_ROLES,
     * ADM_READ_ADMINS, ADM_MANAGE_ADMINS), seeded in this PR alongside the 2
     * new AdministrationRoleController routes that require the first two
     * (the ADMINS pair gates PR8+'s Accesos feature, not yet built — spec
     * R5 explicitly requires all 4 to already be granted only to
     * ROOT_ADMINISTRATION regardless). This supersedes the prior
     * three-permission closed-set assertion (previously updated by
     * becarios-payment-config PR1b) — the closed set is now these 7 ADM_*
     * permissions specifically (still zero PS_* — covered by a separate
     * test below).
     *
     * @test
     */
    public function seeder_grants_exactly_the_seven_expected_adm_permissions_to_root_administration(): void
    {
        $this->seed(RoleSeeder::class);

        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();

        $permissionNames = $role->permissions()->pluck('name')->toArray();

        $this->assertEqualsCanonicalizing(
            [
                'ADM_READ_USERS',
                'ADM_READ_PAYMENT_DATA',
                'ADM_EDIT_PAYMENT_DATA',
                'ADM_READ_ROLES',
                'ADM_MANAGE_ROLES',
                'ADM_READ_ADMINS',
                'ADM_MANAGE_ADMINS',
            ],
            $permissionNames,
            'ROOT_ADMINISTRATION must hold exactly these seven ADM_* permissions and nothing else.'
        );
    }

    /**
     * Spec obs #1592 R5 — "only ROOT_ADMINISTRATION holds all four [new
     * permissions]; no other seeded role holds any of them."
     *
     * @test
     */
    public function no_other_seeded_role_gains_any_of_the_four_new_control_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $newPermissionNames = ['ADM_READ_ROLES', 'ADM_MANAGE_ROLES', 'ADM_READ_ADMINS', 'ADM_MANAGE_ADMINS'];
        $otherRoleNames = Role::where('name', '!=', 'ROOT_ADMINISTRATION')->pluck('name');

        foreach ($otherRoleNames as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $permissionNames = $role->permissions()->pluck('name')->toArray();

            foreach ($newPermissionNames as $newPermissionName) {
                $this->assertNotContains($newPermissionName, $permissionNames, "{$roleName} must not gain {$newPermissionName}.");
            }
        }
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

    /**
     * Task 1b.8: the two payment-data permission grants must be idempotent
     * in isolation too (safe to re-apply via tinker on a non-fresh DB,
     * mirroring the pattern above for ADM_READ_USERS).
     *
     * @test
     */
    public function payment_data_permission_grants_are_idempotent_on_reapply(): void
    {
        $applyGrant = function (): void {
            $role = Role::firstOrCreate(['name' => 'ROOT_ADMINISTRATION']);
            Permission::firstOrCreate(['name' => 'ADM_READ_PAYMENT_DATA'])->syncRoles([$role]);
            Permission::firstOrCreate(['name' => 'ADM_EDIT_PAYMENT_DATA'])->syncRoles([$role]);
        };

        $applyGrant();
        $applyGrant();

        $this->assertSame(1, Permission::where('name', 'ADM_READ_PAYMENT_DATA')->count());
        $this->assertSame(1, Permission::where('name', 'ADM_EDIT_PAYMENT_DATA')->count());

        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();
        $this->assertEqualsCanonicalizing(
            ['ADM_READ_PAYMENT_DATA', 'ADM_EDIT_PAYMENT_DATA'],
            $role->permissions()->pluck('name')->toArray()
        );
    }

    /** @test */
    public function no_other_role_gains_payment_data_permissions_as_a_side_effect(): void
    {
        $this->seed(RoleSeeder::class);

        $otherRoleNames = Role::where('name', '!=', 'ROOT_ADMINISTRATION')->pluck('name');

        foreach ($otherRoleNames as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $permissionNames = $role->permissions()->pluck('name')->toArray();

            $this->assertNotContains('ADM_READ_PAYMENT_DATA', $permissionNames, "{$roleName} must not gain ADM_READ_PAYMENT_DATA.");
            $this->assertNotContains('ADM_EDIT_PAYMENT_DATA', $permissionNames, "{$roleName} must not gain ADM_EDIT_PAYMENT_DATA.");
        }
    }
}
