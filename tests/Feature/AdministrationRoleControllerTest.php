<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers spec (obs #1592) R2 — AdministrationRoleController: role listing,
 * permissions catalog, single-role read, creation (server-hardcoded type),
 * and permission-sync, all isolated to type='ADMINISTRATION' rows.
 *
 * Depends on PR1's B0 retag (ROOT_ADMINISTRATION + 3 ADM_* permissions
 * already type='ADMINISTRATION') and this PR's own 4 new ADM_* permissions
 * (ADM_READ_ROLES, ADM_MANAGE_ROLES, ADM_READ_ADMINS, ADM_MANAGE_ADMINS)
 * added to RoleSeeder.
 */
class AdministrationRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    private function noPermUser(): User
    {
        return User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    // ── index() ──────────────────────────────────────────────────────────

    /** @test */
    public function index_lists_only_administration_type_roles_and_never_leaks_user_type_roles(): void
    {
        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/administration-roles');

        $response->assertStatus(200);
        $names = collect($response->json('roles'))->pluck('name');

        $this->assertTrue($names->contains('ROOT_ADMINISTRATION'));
        $this->assertFalse($names->contains('ROOT'));
        $this->assertFalse($names->contains('DIAMOND'));
    }

    /** @test */
    public function index_requires_adm_read_roles_permission(): void
    {
        $response = $this->actingAs($this->noPermUser())->getJson('/api/admin/administration-roles');

        $response->assertStatus(403);
    }

    // ── permissionsCatalog() ─────────────────────────────────────────────

    /** @test */
    public function permissions_catalog_returns_only_administration_type_permissions(): void
    {
        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/administration-roles/permissions');

        $response->assertStatus(200);
        $names = collect($response->json('permissions'))->pluck('name');

        $this->assertTrue($names->contains('ADM_READ_ROLES'));
        $this->assertFalse($names->contains('PS_READ_USERS'));
        $this->assertFalse($names->contains('CANDIDATES_VIEW'));
    }

    /** @test */
    public function permissions_catalog_requires_adm_read_roles_permission(): void
    {
        $response = $this->actingAs($this->noPermUser())->getJson('/api/admin/administration-roles/permissions');

        $response->assertStatus(403);
    }

    /**
     * Covers permission-descriptions-modules spec "AdministrationPermission
     * catalog contract" — the catalog endpoint's JSON must include
     * `description`/`module` per permission. Requires zero controller code
     * changes: index()/permissionsCatalog() already return full models, so
     * the new nullable columns ride along automatically once they exist.
     *
     * @test
     */
    public function permissions_catalog_includes_description_and_module_for_adm_permissions(): void
    {
        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/administration-roles/permissions');

        $response->assertStatus(200);
        $permissions = collect($response->json('permissions'));
        $readRoles = $permissions->firstWhere('name', 'ADM_READ_ROLES');

        $this->assertNotNull($readRoles);
        $this->assertSame('Roles', $readRoles['module']);
        $this->assertSame('Ver la lista de roles y sus permisos', $readRoles['description']);
    }

    // ── show() ───────────────────────────────────────────────────────────

    /** @test */
    public function show_returns_the_administration_role_with_its_permissions(): void
    {
        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();

        $response = $this->actingAs($this->rootAdmin)->getJson("/api/admin/administration-roles/{$role->id}");

        $response->assertStatus(200);
        $response->assertJson(['res' => true, 'role' => ['id' => $role->id, 'name' => 'ROOT_ADMINISTRATION']]);
        $this->assertNotEmpty($response->json('role.permissions'));
    }

    /** @test */
    public function show_returns_404_for_a_non_administration_role_instead_of_leaking_it(): void
    {
        $root = Role::where('name', 'ROOT')->first();

        $response = $this->actingAs($this->rootAdmin)->getJson("/api/admin/administration-roles/{$root->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function show_returns_404_for_a_nonexistent_id(): void
    {
        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/administration-roles/999999');

        $response->assertStatus(404);
    }

    /** @test */
    public function show_requires_adm_read_roles_permission(): void
    {
        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();

        $response = $this->actingAs($this->noPermUser())->getJson("/api/admin/administration-roles/{$role->id}");

        $response->assertStatus(403);
    }

    // ── store() ──────────────────────────────────────────────────────────

    /** @test */
    public function store_creates_a_role_and_hardcodes_type_ignoring_a_client_submitted_type(): void
    {
        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/administration-roles', [
            'name' => 'ATENCION_PSICOLOGICA',
            'type' => 'USER',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('roles', [
            'name' => 'ATENCION_PSICOLOGICA',
            'type' => 'ADMINISTRATION',
        ]);
    }

    /** @test */
    public function store_rejects_a_duplicate_name_within_administration_type(): void
    {
        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/administration-roles', [
            'name' => 'ROOT_ADMINISTRATION',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Role::where('name', 'ROOT_ADMINISTRATION')->count());
    }

    /** @test */
    public function store_requires_adm_manage_roles_permission(): void
    {
        $response = $this->actingAs($this->noPermUser())->postJson('/api/admin/administration-roles', [
            'name' => 'NUEVO_ROL',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('roles', ['name' => 'NUEVO_ROL']);
    }

    /**
     * Regression: the frontend's RolesTable.vue reads `item.permissions.length`
     * unconditionally to render a permission-count column (index()/show()/
     * syncPermissions() all eager-load 'permissions', so that column always
     * had data — until a role created via store() landed in the same table
     * without ever reloading the page, at which point `permissions` was
     * missing from the JSON entirely and the table threw
     * "Cannot read properties of undefined (reading 'length')" in the
     * browser). store() must return the same shape as every other role
     * read path: a present (even if empty) `permissions` array.
     *
     * @test
     */
    public function store_response_includes_an_empty_permissions_array_matching_every_other_read_path(): void
    {
        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/administration-roles', [
            'name' => 'RECIEN_CREADO',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('role.permissions', []);
    }

    // ── syncPermissions() ────────────────────────────────────────────────

    /** @test */
    public function sync_permissions_changes_effective_access_for_every_account_holding_the_role(): void
    {
        $role = Role::create(['name' => 'ATENCION_PSICOLOGICA', 'type' => 'ADMINISTRATION']);
        $readPermission = Permission::where('name', 'ADM_READ_PAYMENT_DATA')->first();
        $role->syncPermissions([$readPermission->id]);

        $assignedUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $assignedUser->assignRole($role);

        $editPermission = Permission::where('name', 'ADM_EDIT_PAYMENT_DATA')->first();

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/administration-roles/{$role->id}/permissions",
            ['permissions_ids' => [$readPermission->id, $editPermission->id]]
        );

        $response->assertStatus(200);
        $this->assertTrue($assignedUser->getAllPermissions()->pluck('name')->contains('ADM_EDIT_PAYMENT_DATA'));
    }

    /** @test */
    public function sync_permissions_rejects_the_whole_request_when_a_non_administration_permission_id_is_submitted(): void
    {
        $role = Role::create(['name' => 'ATENCION_PSICOLOGICA', 'type' => 'ADMINISTRATION']);
        $validPermission = Permission::where('name', 'ADM_READ_ROLES')->first();
        $userPermission = Permission::where('name', 'PS_READ_USERS')->first();

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/administration-roles/{$role->id}/permissions",
            ['permissions_ids' => [$validPermission->id, $userPermission->id]]
        );

        $response->assertStatus(422);
        $this->assertCount(0, $role->fresh()->permissions);
    }

    /** @test */
    public function sync_permissions_returns_404_for_a_non_administration_role(): void
    {
        $root = Role::where('name', 'ROOT')->first();

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/administration-roles/{$root->id}/permissions",
            ['permissions_ids' => []]
        );

        $response->assertStatus(404);
    }

    /** @test */
    public function sync_permissions_requires_adm_manage_roles_permission(): void
    {
        $role = Role::create(['name' => 'ATENCION_PSICOLOGICA', 'type' => 'ADMINISTRATION']);

        $response = $this->actingAs($this->noPermUser())->putJson(
            "/api/admin/administration-roles/{$role->id}/permissions",
            ['permissions_ids' => []]
        );

        $response->assertStatus(403);
    }
}
