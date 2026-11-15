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
 * RoleController lock-down: ROOT only (ps_root), USER + ADMIN role types,
 * same-type permissions, ROOT role protected. 404 -> 403 -> 422 ordering.
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

    public function test_root_lists_staff_roles_with_type_admin_including_root(): void
    {
        $this->actor('ROOT');
        $expected = collect(self::STAFF_ROLES)->sort()->values()->all();

        $this->assertSame($expected, $this->names($this->getJson('/api/admin/roles?type=ADMIN')->assertOk()->json()));
    }

    public function test_invalid_or_administration_type_falls_back_to_tiers_and_never_lists_administration(): void
    {
        $this->actor('ROOT');
        $expected = collect(self::TIERS)->sort()->values()->all();

        foreach (['ADMINISTRATION', 'foo'] as $type) {
            $names = $this->names($this->getJson("/api/admin/roles?type={$type}")->assertOk()->json());
            $this->assertSame($expected, $names);
            $this->assertNotContains('ROOT_ADMINISTRATION', $names);
        }
        $this->assertNotContains(
            'ROOT_ADMINISTRATION',
            $this->names($this->getJson('/api/admin/roles?type=ADMIN')->json())
        );
    }

    public function test_permissions_listing_is_filtered_by_type(): void
    {
        $this->actor('ROOT');

        $user = collect($this->getJson('/api/admin/roles/permissions')->assertOk()->json('permissions'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['CANDIDATES_VIEW', 'CREATE_VACANT_JR'], $user);

        $fallback = collect($this->getJson('/api/admin/roles/permissions?type=ADMINISTRATION')->assertOk()->json('permissions'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['CANDIDATES_VIEW', 'CREATE_VACANT_JR'], $fallback);

        $admin = collect($this->getJson('/api/admin/roles/permissions?type=ADMIN')->assertOk()->json('permissions'))->pluck('name');
        $this->assertTrue($admin->contains('PS_VIEW_ALL_CAMPUSES'));
        $this->assertTrue($admin->contains('PS_ROLES'));
        $this->assertSame($admin->count(), $admin->filter(fn ($n) => str_starts_with($n, 'PS_'))->count());
    }

    public function test_only_root_role_passes_the_gate_on_all_three_routes(): void
    {
        $gold = $this->roleId('GOLD');

        foreach (['ROOT_JOB', 'ROOT_CAMPUS', 'ADMIN_JOB', 'ROOT_ADMINISTRATION'] as $role) {
            $this->actor($role);
            $this->getJson('/api/admin/roles')->assertForbidden();
            $this->getJson('/api/admin/roles/permissions')->assertForbidden();
            $this->putJson("/api/admin/roles/{$gold}", $this->tierPayload([]))->assertForbidden();
        }
    }

    public function test_root_job_with_ps_roles_granted_directly_is_still_forbidden(): void
    {
        $actor = $this->actor('ROOT_JOB');
        $actor->givePermissionTo('PS_ROLES');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->getJson('/api/admin/roles')->assertForbidden();
        $this->getJson('/api/admin/roles/permissions')->assertForbidden();
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

    // ---- staff update ----

    public function test_root_syncs_staff_role_permissions_without_creating_configuration(): void
    {
        $this->actor('ROOT');
        $id = $this->roleId('ROOT_CAMPUS');
        $ids = [$this->permId('PS_READ_USERS'), $this->permId('PS_VIEW_ALL_CAMPUSES')];

        $this->putJson("/api/admin/roles/{$id}", [
            'permissions_ids' => $ids,
            'num_job_vacancies' => 99,
            'name' => 'RENAMED',
        ])->assertOk()->assertJsonPath('data.name', 'ROOT_CAMPUS');

        $this->assertSame(['PS_READ_USERS', 'PS_VIEW_ALL_CAMPUSES'], $this->permNames('ROOT_CAMPUS'));
        $this->assertDatabaseMissing('role_configuration', ['role_id' => $id]);
    }

    public function test_staff_update_rejects_other_type_permissions_unchanged(): void
    {
        $this->actor('ROOT');
        $id = $this->roleId('ROOT_CAMPUS');
        $before = $this->permNames('ROOT_CAMPUS');

        foreach (['CANDIDATES_VIEW', 'ADM_READ_USERS'] as $name) {
            $this->putJson("/api/admin/roles/{$id}", ['permissions_ids' => [$this->permId($name)]])->assertStatus(422);
        }
        $this->putJson("/api/admin/roles/{$id}", ['permissions_ids' => [999999]])->assertStatus(422);
        $this->putJson("/api/admin/roles/{$id}", ['num_job_vacancies' => 1])->assertStatus(422);

        $this->assertSame($before, $this->permNames('ROOT_CAMPUS'));
    }

    // ---- protection and ordering ----

    public function test_root_role_cannot_be_edited(): void
    {
        $this->actor('ROOT');
        $root = $this->roleId('ROOT');
        $before = $this->permNames('ROOT');

        $this->putJson("/api/admin/roles/{$root}", ['permissions_ids' => []])->assertForbidden();
        $this->putJson("/api/admin/roles/{$root}", $this->tierPayload([]))->assertForbidden();
        $this->putJson("/api/admin/roles/{$root}", ['permissions_ids' => [999999]])->assertForbidden();

        $this->assertSame($before, $this->permNames('ROOT'));
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
