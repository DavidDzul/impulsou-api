<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RoleSeeder must produce the same role/permission type semantics as staging
 * (design-amendment-2 B1): tiers + 2 client permissions are USER; staff roles
 * and every PS_* permission are ADMIN; ADMINISTRATION data is untouched.
 */
class RoleSeederTypeAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private const TIER_ROLES = ['BASIC', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'DIAMOND'];

    private const STAFF_ROLES = ['ROOT', 'ROOT_CAMPUS', 'YUCATAN', 'ATTENDANCE', 'ADMIN_STUDENT', 'ROOT_JOB', 'ADMIN_JOB'];

    private const CLIENT_PERMISSIONS = ['CANDIDATES_VIEW', 'CREATE_VACANT_JR'];

    /** @test */
    public function tier_roles_are_type_user(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (self::TIER_ROLES as $name) {
            $this->assertSame('USER', Role::where('name', $name)->firstOrFail()->type, "$name must be USER.");
        }
    }

    /** @test */
    public function staff_roles_are_type_admin(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (self::STAFF_ROLES as $name) {
            $this->assertSame('ADMIN', Role::where('name', $name)->firstOrFail()->type, "$name must be ADMIN.");
        }
    }

    /** @test */
    public function client_permissions_are_type_user(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (self::CLIENT_PERMISSIONS as $name) {
            $this->assertSame('USER', Permission::where('name', $name)->firstOrFail()->type, "$name must be USER.");
        }
    }

    /** @test */
    public function every_ps_permission_is_type_admin(): void
    {
        $this->seed(RoleSeeder::class);

        $ps = Permission::all()->filter(fn ($p) => str_starts_with($p->name, 'PS_'));

        $this->assertGreaterThanOrEqual(33, $ps->count());
        foreach ($ps as $permission) {
            $this->assertSame('ADMIN', $permission->type, "{$permission->name} must be ADMIN.");
        }
        $this->assertTrue($ps->contains('name', 'PS_VIEW_ALL_CAMPUSES'));
    }

    /** @test */
    public function the_only_user_permissions_are_the_two_client_ones(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertEqualsCanonicalizing(
            self::CLIENT_PERMISSIONS,
            Permission::where('type', 'USER')->pluck('name')->all()
        );
        $this->assertEqualsCanonicalizing(
            self::TIER_ROLES,
            Role::where('type', 'USER')->pluck('name')->all()
        );
    }

    /** @test */
    public function administration_roles_and_permissions_keep_their_type(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame('ADMINISTRATION', Role::where('name', 'ROOT_ADMINISTRATION')->firstOrFail()->type);
        $adm = Permission::all()->filter(fn ($p) => str_starts_with($p->name, 'ADM_'));
        $this->assertGreaterThan(0, $adm->count());
        foreach ($adm as $permission) {
            $this->assertSame('ADMINISTRATION', $permission->type, "{$permission->name} must be ADMINISTRATION.");
        }
    }

    /** @test */
    public function ps_roles_is_granted_to_root_only(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame(
            ['ROOT'],
            Permission::where('name', 'PS_ROLES')->firstOrFail()->roles()->pluck('name')->all()
        );
    }

    /** @test */
    public function root_holds_every_ps_permission(): void
    {
        $this->seed(RoleSeeder::class);

        $root = Role::where('name', 'ROOT')->firstOrFail();
        $missing = Permission::all()
            ->filter(fn ($p) => str_starts_with($p->name, 'PS_'))
            ->pluck('name')
            ->diff($root->permissions()->pluck('name'));

        $this->assertSame([], $missing->values()->all(), 'ROOT must hold every PS_* permission.');
    }

    /** @test */
    public function ps_view_all_campuses_is_granted_to_root_only(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame(
            ['ROOT'],
            Permission::where('name', 'PS_VIEW_ALL_CAMPUSES')->firstOrFail()->roles()->pluck('name')->all()
        );
    }

    /** @test */
    public function seeding_twice_is_idempotent_and_keeps_types_and_grants(): void
    {
        $this->seed(RoleSeeder::class);
        $roles = Role::count();
        $permissions = Permission::count();
        $grants = \DB::table('role_has_permissions')->count();

        $this->seed(RoleSeeder::class);

        $this->assertSame($roles, Role::count());
        $this->assertSame($permissions, Permission::count());
        $this->assertSame($grants, \DB::table('role_has_permissions')->count());
        $this->assertSame('ADMIN', Role::where('name', 'ROOT')->firstOrFail()->type);
        $this->assertSame('USER', Role::where('name', 'DIAMOND')->firstOrFail()->type);
        $this->assertSame('ADMIN', Permission::where('name', 'PS_READ_USERS')->firstOrFail()->type);
        $this->assertSame(
            ['ROOT'],
            Permission::where('name', 'PS_ROLES')->firstOrFail()->roles()->pluck('name')->all()
        );
    }
}
