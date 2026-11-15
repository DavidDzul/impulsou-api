<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * RoleController lock-down: gate ps_permission:PS_ROLES (ROOT always passes, ROOT_JOB holds
 * PS_ROLES), business plan roles (type USER) ONLY. Staff (ADMIN, incl. ROOT) and ADMINISTRATION roles are 404 and never
 * listed. Permissions must be type USER. 403 (gate) -> 404 -> 422 ordering.
 */
class RoleControllerSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_ROLES = [
        'ROOT', 'ROOT_CAMPUS', 'ROOT_JOB', 'ADMIN_JOB', 'ADMIN_STUDENT', 'YUCATAN', 'ATTENDANCE',
    ];

    private const TIERS = ['BASIC', 'SILVER', 'GOLD', 'PLATINUM', 'DIAMOND', 'BRONZE'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actor(string $role, string $campus = 'MERIDA'): User
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'campus' => $campus, 'active' => true]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function roleId(string $name): int
    {
        return Role::where('name', $name)->firstOrFail()->id;
    }

    private function permId(string $name): int
    {
        return Permission::where('name', $name)->firstOrFail()->id;
    }

    private function permNames(string $role): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::where('name', $role)->first()->permissions()->pluck('name')->sort()->values()->all();
    }

    private function tierPayload(array $permissionIds, array $overrides = []): array
    {
        return array_merge([
            'unlimited_jobs' => false, 'num_job_vacancies' => 7,
            'unlimited_professionals' => false, 'num_professional_vacancies' => 8,
            'unlimited_jr' => false, 'num_jr_vacancies' => 9,
            'unlimited_visualizations' => false, 'num_visualizations' => 10,
            'permissions_ids' => $permissionIds,
        ], $overrides);
    }

    private function names(array $json): array
    {
        return collect($json['roles'])->pluck('name')->sort()->values()->all();
    }

    // ---- gate ----

    public function test_root_lists_tiers_by_default_and_with_type_user(): void
    {
        $this->actor('ROOT');
        $expected = collect(self::TIERS)->sort()->values()->all();

        $this->assertSame($expected, $this->names($this->getJson('/api/admin/roles')->assertOk()->json()));
        $this->assertSame($expected, $this->names($this->getJson('/api/admin/roles?type=USER')->assertOk()->json()));
    }

    public function test_type_admin_is_ignored_and_staff_roles_are_never_listed(): void
    {
        $this->actor('ROOT');
        $expected = collect(self::TIERS)->sort()->values()->all();

        $names = $this->names($this->getJson('/api/admin/roles?type=ADMIN')->assertOk()->json());

        $this->assertSame($expected, $names);
        foreach (self::STAFF_ROLES as $staff) {
            $this->assertNotContains($staff, $names);
        }
    }

    public function test_any_type_value_falls_back_to_tiers_and_never_lists_administration(): void
    {
        $this->actor('ROOT');
        $expected = collect(self::TIERS)->sort()->values()->all();

        foreach (['ADMIN', 'ADMINISTRATION', 'foo'] as $type) {
            $names = $this->names($this->getJson("/api/admin/roles?type={$type}")->assertOk()->json());
            $this->assertSame($expected, $names);
            $this->assertNotContains('ROOT_ADMINISTRATION', $names);
        }
    }

    public function test_permissions_listing_only_returns_user_permissions(): void
    {
        $this->actor('ROOT');

        $user = collect($this->getJson('/api/admin/roles/permissions')->assertOk()->json('permissions'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['CANDIDATES_VIEW', 'CREATE_VACANT_JR'], $user);

        $fallback = collect($this->getJson('/api/admin/roles/permissions?type=ADMINISTRATION')->assertOk()->json('permissions'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['CANDIDATES_VIEW', 'CREATE_VACANT_JR'], $fallback);

        $admin = collect($this->getJson('/api/admin/roles/permissions?type=ADMIN')->assertOk()->json('permissions'))->pluck('name');
        $this->assertEqualsCanonicalizing(['CANDIDATES_VIEW', 'CREATE_VACANT_JR'], $admin->all());
        $this->assertSame(0, $admin->filter(fn ($n) => str_starts_with($n, 'PS_') || str_starts_with($n, 'ADM_'))->count());
    }

    public function test_roles_without_ps_roles_are_forbidden_on_all_three_routes(): void
    {
        $gold = $this->roleId('GOLD');

        foreach (['ROOT_CAMPUS', 'ADMIN_JOB', 'ROOT_ADMINISTRATION'] as $role) {
            $this->actor($role);
            $this->getJson('/api/admin/roles')->assertForbidden();
            $this->getJson('/api/admin/roles/permissions')->assertForbidden();
            $this->putJson("/api/admin/roles/{$gold}", $this->tierPayload([]))->assertForbidden();
        }
    }

    public function test_root_job_holds_ps_roles_and_passes_on_all_three_routes(): void
    {
        $gold = $this->roleId('GOLD');
        $this->actor('ROOT_JOB');

        $this->getJson('/api/admin/roles')->assertOk();
        $this->getJson('/api/admin/roles/permissions')->assertOk();
        $this->putJson("/api/admin/roles/{$gold}", $this->tierPayload([]))->assertOk();
    }

    public function test_root_job_is_forbidden_once_ps_roles_is_revoked_from_it(): void
    {
        $this->actor('ROOT_JOB');
        Role::where('name', 'ROOT_JOB')->first()->revokePermissionTo('PS_ROLES');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/admin/roles')->assertForbidden();
        $this->getJson('/api/admin/roles/permissions')->assertForbidden();
    }

    public function test_a_role_without_ps_roles_passes_when_it_is_granted_directly(): void
    {
        $actor = $this->actor('ROOT_CAMPUS');
        $this->getJson('/api/admin/roles')->assertForbidden();

        $actor->givePermissionTo('PS_ROLES');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/admin/roles')->assertOk();
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/admin/roles')->assertUnauthorized();
        $this->getJson('/api/admin/roles/permissions')->assertUnauthorized();
        $this->putJson('/api/admin/roles/1', [])->assertUnauthorized();
    }

    public function test_root_still_passes_after_ps_roles_is_revoked(): void
    {
        $this->actor('ROOT');
        Role::where('name', 'ROOT')->first()->revokePermissionTo('PS_ROLES');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/admin/roles')->assertOk();
    }

    // ---- tier update ----

    public function test_root_updates_a_tier_with_limits_and_user_permissions(): void
    {
        $this->actor('ROOT');
        $gold = $this->roleId('GOLD');

        $response = $this->putJson("/api/admin/roles/{$gold}", $this->tierPayload([$this->permId('CANDIDATES_VIEW')]));

        $response->assertOk()
            ->assertJsonPath('data.name', 'GOLD')
            ->assertJsonPath('data.configuration.num_job_vacancies', 7)
            ->assertJsonStructure(['data' => ['id', 'name', 'configuration', 'permissions', 'created_at']]);
        $this->assertSame(['CANDIDATES_VIEW'], $this->permNames('GOLD'));
        $this->assertDatabaseHas('role_configuration', ['role_id' => $gold, 'num_visualizations' => 10]);
    }

    public function test_tier_update_rejects_ps_adm_and_missing_permission_ids_unchanged(): void
    {
        $this->actor('ROOT');
        $gold = $this->roleId('GOLD');
        $before = $this->permNames('GOLD');

        foreach ([$this->permId('PS_READ_USERS'), $this->permId('ADM_READ_USERS'), 999999] as $bad) {
            $this->putJson("/api/admin/roles/{$gold}", $this->tierPayload([$bad]))->assertStatus(422);
        }

        $this->assertSame($before, $this->permNames('GOLD'));
        $this->assertDatabaseMissing('role_configuration', ['role_id' => $gold, 'num_job_vacancies' => 7]);
    }

    public function test_tier_update_without_permissions_ids_is_422_and_leaves_permissions_unchanged(): void
    {
        $this->actor('ROOT');
        $diamond = $this->roleId('DIAMOND');
        $before = $this->permNames('DIAMOND');
        $this->assertNotEmpty($before);

        $payload = $this->tierPayload([]);
        unset($payload['permissions_ids']);

        $this->putJson("/api/admin/roles/{$diamond}", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions_ids');

        $this->assertSame($before, $this->permNames('DIAMOND'));
    }

    public function test_an_explicit_empty_permissions_ids_still_clears_the_tier(): void
    {
        $this->actor('ROOT');
        $diamond = $this->roleId('DIAMOND');

        $this->putJson("/api/admin/roles/{$diamond}", $this->tierPayload([]))->assertOk();

        $this->assertSame([], $this->permNames('DIAMOND'));
    }

    public function test_name_in_payload_is_ignored_on_tier_update(): void
    {
        $this->actor('ROOT');
        $gold = $this->roleId('GOLD');

        $this->putJson("/api/admin/roles/{$gold}", $this->tierPayload([], ['name' => 'ROOT']))
            ->assertOk()
            ->assertJsonPath('data.name', 'GOLD');

        $this->assertSame('GOLD', Role::find($gold)->name);
        $this->assertSame(1, Role::where('name', 'ROOT')->count());
    }

    // ---- staff and root roles are out of scope ----

    public function test_staff_role_update_is_404_and_unchanged(): void
    {
        $this->actor('ROOT');
        $id = $this->roleId('ROOT_CAMPUS');
        $before = $this->permNames('ROOT_CAMPUS');

        $this->putJson("/api/admin/roles/{$id}", ['permissions_ids' => [$this->permId('PS_READ_USERS')]])->assertNotFound();
        $this->putJson("/api/admin/roles/{$id}", $this->tierPayload([]))->assertNotFound();
        $this->putJson("/api/admin/roles/{$id}", ['permissions_ids' => [999999]])->assertNotFound();

        $this->assertSame($before, $this->permNames('ROOT_CAMPUS'));
        $this->assertDatabaseMissing('role_configuration', ['role_id' => $id]);
    }

    public function test_every_staff_role_including_root_is_404_on_update(): void
    {
        $this->actor('ROOT');

        foreach (self::STAFF_ROLES as $name) {
            $before = $this->permNames($name);
            $this->putJson('/api/admin/roles/'.$this->roleId($name), ['permissions_ids' => []])->assertNotFound();
            $this->assertSame($before, $this->permNames($name));
        }
    }

    public function test_administration_and_missing_roles_are_404_even_with_invalid_payload(): void
    {
        $this->actor('ROOT');

        foreach (['ROOT_ADMINISTRATION'] as $name) {
            $id = $this->roleId($name);
            $before = $this->permNames($name);
            $this->putJson("/api/admin/roles/{$id}", ['permissions_ids' => []])->assertNotFound();
            $this->putJson("/api/admin/roles/{$id}", ['permissions_ids' => [999999]])->assertNotFound();
            $this->assertSame($before, $this->permNames($name));
        }
        $this->putJson('/api/admin/roles/999999', ['permissions_ids' => []])->assertNotFound();
    }

    public function test_administration_type_role_that_is_not_root_administration_is_404(): void
    {
        $this->actor('ROOT');
        $role = Role::create(['name' => 'CUSTOM_ADM', 'type' => 'ADMINISTRATION']);

        $this->putJson("/api/admin/roles/{$role->id}", ['permissions_ids' => []])->assertNotFound();
    }

    public function test_unused_verbs_are_not_routable(): void
    {
        $this->actor('ROOT');
        $gold = $this->roleId('GOLD');

        foreach ([
            $this->postJson('/api/admin/roles', ['name' => 'X']),
            $this->getJson("/api/admin/roles/{$gold}"),
            $this->deleteJson("/api/admin/roles/{$gold}"),
        ] as $response) {
            $this->assertContains($response->getStatusCode(), [404, 405]);
        }
        $this->assertNotNull(Role::find($gold));
    }
}
