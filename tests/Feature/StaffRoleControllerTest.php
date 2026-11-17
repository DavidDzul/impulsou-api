<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PsPermissionCatalog;
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
        $this->assertSame('PS_GROUP_JOBS', $items->firstWhere('name', 'PS_ROLES')['menu_group']);
        $this->assertSame('PS_GROUP_JOBS', $items->firstWhere('name', 'PS_GRAPHICS')['menu_group']);
        $this->assertSame('PS_GROUP_ATTENDANCE', $items->firstWhere('name', 'PS_NOTICES')['menu_group']);
        $this->assertSame('PS_GROUP_CONFIG', $items->firstWhere('name', 'PS_GENERATIONS')['menu_group']);

        $this->assertSame('Otros', $modules->last()['module']);
        $this->assertSame('PS_UNKNOWN_EXTRA', $modules->last()['permissions'][0]['name']);
    }

    // ---- Write endpoints (PR2b) ----

    public function test_write_endpoints_reject_guests_and_non_root_actors(): void
    {
        $role = Role::forceCreate(['name' => 'TARGET_ROLE', 'type' => 'ADMIN', 'guard_name' => 'web']);
        $url = self::INDEX . '/' . $role->id . '/permissions';

        $this->postJson(self::INDEX, ['name' => 'NEW_ROLE'])->assertStatus(401);
        $this->putJson($url, ['permissions_ids' => []])->assertStatus(401);

        foreach (['ROOT_JOB', 'ROOT_CAMPUS', 'ADMIN_STUDENT', 'ROOT_ADMINISTRATION'] as $actor) {
            $this->actor($actor);
            $this->postJson(self::INDEX, ['name' => 'NEW_ROLE'])->assertStatus(403);
            $this->putJson($url, ['permissions_ids' => []])->assertStatus(403);
        }
        $this->assertDatabaseMissing('roles', ['name' => 'NEW_ROLE']);
    }

    public function test_root_creates_a_staff_role(): void
    {
        $this->actor('ROOT');

        $response = $this->postJson(self::INDEX, ['name' => 'SOPORTE_2'])
            ->assertStatus(201)
            ->assertJsonPath('res', true)
            ->assertJsonPath('role.name', 'SOPORTE_2')
            ->assertJsonPath('role.type', 'ADMIN')
            ->assertJsonPath('role.permissions_ids', [])
            ->assertJsonPath('role.users_count', 0);

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'type', 'permissions_ids', 'users_count'],
            array_keys($response->json('role'))
        );
        $this->assertDatabaseHas('roles', ['name' => 'SOPORTE_2', 'type' => 'ADMIN', 'guard_name' => 'web']);
    }

    public function test_create_rejects_reserved_duplicate_and_badly_formatted_names(): void
    {
        $this->actor('ROOT');
        $before = Role::count();

        $invalid = [
            'ROOT', 'root', 'Root', 'ROOT_JOB', 'ROOT_ADMINISTRATION', 'YUCATAN',
            'BASIC', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'DIAMOND',
            'ROOT_CAMPUS', 'root_campus', 'gold', // duplicates (case-insensitive)
            'lower_case', 'AB', '1ABC', 'WITH SPACE', 'BAD-DASH', str_repeat('A', 51), '', null,
        ];
        foreach ($invalid as $name) {
            $this->postJson(self::INDEX, ['name' => $name])->assertStatus(422);
        }
        $this->postJson(self::INDEX, [])->assertStatus(422);

        $this->assertSame($before, Role::count());
    }

    public function test_create_rejects_a_case_variant_of_an_existing_role(): void
    {
        $this->actor('ROOT');
        $this->postJson(self::INDEX, ['name' => 'SOPORTE_2'])->assertStatus(201);
        $this->postJson(self::INDEX, ['name' => 'SOPORTE_2'])->assertStatus(422);

        Role::forceCreate(['name' => 'Mixed_Case', 'type' => 'ADMIN', 'guard_name' => 'web']);
        $this->postJson(self::INDEX, ['name' => 'MIXED_CASE'])->assertStatus(422);
    }

    public function test_root_syncs_permissions_of_a_staff_role_and_empty_array_clears_them(): void
    {
        $this->actor('ROOT');
        $role = Role::forceCreate(['name' => 'TARGET_ROLE', 'type' => 'ADMIN', 'guard_name' => 'web']);
        $ids = PsPermissionCatalog::grantableQuery()->limit(3)->pluck('id')->all();
        $url = self::INDEX . '/' . $role->id . '/permissions';

        $this->putJson($url, ['permissions_ids' => $ids])
            ->assertOk()
            ->assertJsonPath('res', true)
            ->assertJsonPath('role.id', $role->id);
        $this->assertEqualsCanonicalizing($ids, $role->fresh()->permissions()->pluck('permissions.id')->all());

        $replacement = PsPermissionCatalog::grantableQuery()->whereNotIn('id', $ids)->limit(1)->pluck('id')->all();
        $response = $this->putJson($url, ['permissions_ids' => array_merge($replacement, $replacement)])->assertOk();
        $this->assertSame($replacement, $response->json('role.permissions_ids'));
        $this->assertSame($replacement, $role->fresh()->permissions()->pluck('permissions.id')->all());

        $this->putJson($url, ['permissions_ids' => []])->assertOk()->assertJsonPath('role.permissions_ids', []);
        $this->assertSame(0, $role->fresh()->permissions()->count());
    }

    public function test_sync_with_non_grantable_ids_is_all_or_nothing(): void
    {
        $this->actor('ROOT');
        $role = Role::forceCreate(['name' => 'TARGET_ROLE', 'type' => 'ADMIN', 'guard_name' => 'web']);
        $kept = PsPermissionCatalog::grantableQuery()->limit(2)->pluck('id')->all();
        $role->syncPermissions($kept);
        $valid = PsPermissionCatalog::grantableQuery()->whereNotIn('id', $kept)->value('id');
        $sanctum = Permission::forceCreate(['name' => 'PS_SANCTUM_ROW', 'type' => 'ADMIN', 'guard_name' => 'sanctum']);
        $url = self::INDEX . '/' . $role->id . '/permissions';

        $bad = [
            Permission::where('name', 'PS_MANAGE_ADMINS')->value('id'),
            Permission::where('name', 'like', 'ADM%')->value('id'),
            Permission::where('type', 'USER')->value('id'),
            $sanctum->id,
            999999,
            'abc',
        ];
        foreach ($bad as $badId) {
            $this->assertNotNull($badId);
            $this->putJson($url, ['permissions_ids' => [$valid, $badId]])->assertStatus(422);
            $this->assertEqualsCanonicalizing($kept, $role->fresh()->permissions()->pluck('permissions.id')->all());
        }
    }

    public function test_sync_requires_the_permissions_ids_key(): void
    {
        $this->actor('ROOT');
        $role = Role::forceCreate(['name' => 'TARGET_ROLE', 'type' => 'ADMIN', 'guard_name' => 'web']);
        $role->syncPermissions(PsPermissionCatalog::grantableQuery()->limit(2)->pluck('id')->all());

        $this->putJson(self::INDEX . '/' . $role->id . '/permissions', [])->assertStatus(422);
        $this->assertSame(2, $role->fresh()->permissions()->count());
    }

    public function test_sync_on_out_of_scope_roles_is_404_even_with_an_invalid_payload(): void
    {
        $this->actor('ROOT');
        $sanctumRole = Role::forceCreate(['name' => 'AuxiliarX', 'type' => 'ADMIN', 'guard_name' => 'sanctum']);
        $ids = [
            Role::where('name', 'ROOT')->value('id'), Role::where('name', 'YUCATAN')->value('id'),
            Role::where('name', 'ROOT_ADMINISTRATION')->value('id'), Role::where('name', 'GOLD')->value('id'),
            $sanctumRole->id, 999999,
        ];
        $rootBefore = Role::where('name', 'ROOT')->firstOrFail()->permissions()->count();

        foreach ($ids as $id) {
            $this->assertNotNull($id);
            $this->putJson(self::INDEX . "/$id/permissions", ['permissions_ids' => []])->assertStatus(404);
            $this->putJson(self::INDEX . "/$id/permissions", [])->assertStatus(404);
        }
        $this->assertSame($rootBefore, Role::where('name', 'ROOT')->firstOrFail()->permissions()->count());
    }

    public function test_staff_roles_cannot_be_renamed_or_deleted(): void
    {
        $this->actor('ROOT');
        $role = Role::forceCreate(['name' => 'TARGET_ROLE', 'type' => 'ADMIN', 'guard_name' => 'web']);

        // No such routes exist: 404 (no route) or 405 (method not allowed) are both acceptable.
        $this->assertContains($this->putJson(self::INDEX . '/' . $role->id, ['name' => 'OTHER_NAME'])->status(), [404, 405]);
        $this->assertContains($this->deleteJson(self::INDEX . '/' . $role->id)->status(), [404, 405]);
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'TARGET_ROLE']);
    }
}
