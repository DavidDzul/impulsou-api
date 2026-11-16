<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Staff roles read endpoints: GET api/admin/staff-roles and GET api/admin/staff-roles/permissions.
 * Gate ps_permission:PS_MANAGE_ADMINS (ROOT bypass only; nobody else holds it).
 */
class StaffRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'api/admin/staff-roles';
    private const CATALOG = 'api/admin/staff-roles/permissions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'campus' => 'MERIDA', 'active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_gets_401_on_both_endpoints(): void
    {
        $this->getJson(self::INDEX)->assertStatus(401);
        $this->getJson(self::CATALOG)->assertStatus(401);
    }

    public function test_non_root_actors_get_403_on_both_endpoints(): void
    {
        foreach (['ROOT_JOB', 'ROOT_CAMPUS', 'ADMIN_STUDENT', 'ROOT_ADMINISTRATION'] as $role) {
            $this->actor($role);
            $this->getJson(self::INDEX)->assertStatus(403);
            $this->getJson(self::CATALOG)->assertStatus(403);
        }
    }

    public function test_staff_role_holding_every_ps_permission_except_manage_is_forbidden(): void
    {
        $role = Role::create(['name' => 'ALL_BUT_MANAGE', 'type' => 'ADMIN']);
        $role->syncPermissions(
            Permission::where('type', 'ADMIN')->where('name', '!=', 'PS_MANAGE_ADMINS')->get()
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor('ALL_BUT_MANAGE');

        $this->getJson(self::INDEX)->assertStatus(403);
        $this->getJson(self::CATALOG)->assertStatus(403);
    }

    public function test_business_user_gets_403(): void
    {
        $user = User::factory()->create(['user_type' => 'BUSINESS', 'active' => true]);
        Sanctum::actingAs($user);

        $this->getJson(self::INDEX)->assertStatus(403);
        $this->getJson(self::CATALOG)->assertStatus(403);
    }

    public function test_root_passes_even_when_manage_permission_is_revoked_from_its_role(): void
    {
        $root = Role::where('name', 'ROOT')->firstOrFail();
        $root->revokePermissionTo('PS_MANAGE_ADMINS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor('ROOT');

        $this->getJson(self::INDEX)->assertOk();
        $this->getJson(self::CATALOG)->assertOk();
    }

    public function test_index_lists_only_staff_roles_with_permissions_ids_and_users_count(): void
    {
        Role::forceCreate(['name' => 'Auxiliar', 'type' => 'ADMIN', 'guard_name' => 'sanctum']);
        $this->actor('ROOT');
        $jobPermissionIds = Role::where('name', 'ROOT_JOB')->firstOrFail()->permissions()->pluck('id')->sort()->values()->all();
        $holder = User::factory()->create(['user_type' => 'ADMIN', 'campus' => 'MERIDA', 'active' => true]);
        $holder->assignRole('ADMIN_JOB');

        $response = $this->getJson(self::INDEX)->assertOk()->assertJsonPath('res', true);
        $roles = collect($response->json('roles'));
        $names = $roles->pluck('name');

        foreach (['ROOT', 'YUCATAN', 'Auxiliar', 'ROOT_ADMINISTRATION', 'BASIC', 'GOLD'] as $excluded) {
            $this->assertNotContains($excluded, $names, "$excluded must not be listed");
        }
        $this->assertContains('ROOT_JOB', $names);
        $this->assertContains('ADMIN_JOB', $names);
        $this->assertTrue($roles->every(fn ($r) => $r['type'] === 'ADMIN'));

        $rootJob = $roles->firstWhere('name', 'ROOT_JOB');
        $this->assertEqualsCanonicalizing(['id', 'name', 'type', 'permissions_ids', 'users_count'], array_keys($rootJob));
        $this->assertSame($jobPermissionIds, collect($rootJob['permissions_ids'])->sort()->values()->all());
        $this->assertSame(1, $roles->firstWhere('name', 'ADMIN_JOB')['users_count']);
    }

    public function test_catalog_is_grouped_and_excludes_non_grantable_permissions(): void
    {
        Permission::forceCreate(['name' => 'PS_UNKNOWN_EXTRA', 'type' => 'ADMIN', 'guard_name' => 'web']);
        Permission::forceCreate(['name' => 'PS_SANCTUM_ROW', 'type' => 'ADMIN', 'guard_name' => 'sanctum']);
        $this->actor('ROOT');

        $response = $this->getJson(self::CATALOG)->assertOk()->assertJsonPath('res', true);
        $modules = collect($response->json('modules'));
        $items = $modules->flatMap(fn ($m) => $m['permissions']);
        $names = $items->pluck('name');

        $this->assertNotContains('PS_MANAGE_ADMINS', $names);
        $this->assertNotContains('PS_SANCTUM_ROW', $names);
        $this->assertTrue($names->every(fn ($n) => str_starts_with($n, 'PS_')), 'only PS_* may be listed');
        $this->assertContains('PS_GROUP_CONFIG', $names);

        foreach ($items as $item) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'description', 'kind', 'menu_group'], array_keys($item));
        }
        $this->assertSame('menu-group', $items->firstWhere('name', 'PS_GROUP_CONFIG')['kind']);
        $this->assertSame('PS_GROUP_CONFIG', $items->firstWhere('name', 'PS_ROLES')['menu_group']);

        $this->assertSame('Otros', $modules->last()['module']);
        $this->assertSame('PS_UNKNOWN_EXTRA', $modules->last()['permissions'][0]['name']);
    }
}
