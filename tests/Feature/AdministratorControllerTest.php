<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Covers spec (obs #1592) R3 (create + correo uniqueness) and A1 (Accesos
 * list scoping, all 3 scenarios) — AdministratorController read+create side
 * (PR3a). assignRole + self-demotion guard (R3 assign, R4/A2) land in PR3b.
 *
 * Depends on PR1's B0 retag and PR2's ADM_READ_ADMINS/ADM_MANAGE_ADMINS
 * permissions (seeded, granted only to ROOT_ADMINISTRATION).
 */
class AdministratorControllerTest extends TestCase
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

    // ── store() ──────────────────────────────────────────────────────────

    /** @test */
    public function store_creates_an_administrator_account_with_zero_roles_and_a_hashed_password(): void
    {
        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/administrators', [
            'first_name' => 'Ana',
            'last_name' => 'Gomez',
            'email' => 'ana.gomez@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertStatus(201);

        $administrator = User::where('email', 'ana.gomez@example.com')->first();
        $this->assertNotNull($administrator);
        $this->assertSame('ADMIN', $administrator->user_type);
        $this->assertCount(0, $administrator->roles);
        $this->assertNotSame('secret-password', $administrator->password);
        $this->assertTrue(Hash::check('secret-password', $administrator->password));
    }

    /** @test */
    public function store_ignores_any_submitted_role_field_and_never_assigns_a_role(): void
    {
        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/administrators', [
            'first_name' => 'Luis',
            'last_name' => 'Perez',
            'email' => 'luis.perez@example.com',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'role' => 'ROOT_ADMINISTRATION',
        ]);

        $response->assertStatus(201);

        $administrator = User::where('email', 'luis.perez@example.com')->first();
        $this->assertCount(0, $administrator->roles);
    }

    /** @test */
    public function store_rejects_a_duplicate_email_and_creates_no_row(): void
    {
        User::factory()->create(['user_type' => 'ADMIN', 'email' => 'dup@example.com']);

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/administrators', [
            'first_name' => 'Dup',
            'last_name' => 'Licado',
            'email' => 'dup@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, User::where('email', 'dup@example.com')->count());
    }

    /** @test */
    public function store_requires_adm_manage_admins_permission(): void
    {
        $response = $this->actingAs($this->noPermUser())->postJson('/api/admin/administrators', [
            'first_name' => 'Sin',
            'last_name' => 'Permiso',
            'email' => 'sinpermiso@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'sinpermiso@example.com']);
    }

    /**
     * Regression: mirrors AdministrationRoleControllerTest's identical fix.
     * index()/show() both eager-load 'roles' (so AccesosTable.vue's
     * `item.roles[0]?.name ?? '—'` always had a `roles` array to read), but
     * store() never did — a freshly created administrator crashed that same
     * table the moment it landed there without a page reload:
     * "Cannot read properties of undefined (reading '0')".
     *
     * @test
     */
    public function store_response_includes_an_empty_roles_array_matching_every_other_read_path(): void
    {
        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/administrators', [
            'first_name' => 'Nuevo',
            'last_name' => 'Admin',
            'email' => 'nuevo.admin@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('administrator.roles', []);
    }

    // ── index() — Accesos list scope (A1) ───────────────────────────────────

    /** @test */
    public function index_excludes_an_admin_type_account_holding_a_non_administration_role(): void
    {
        $rootCampus = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'rootcampus@example.com']);
        $rootCampus->assignRole('ROOT_CAMPUS');

        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/administrators');

        $response->assertStatus(200);
        $ids = collect($response->json('administrators'))->pluck('id');
        $this->assertFalse($ids->contains($rootCampus->id));
    }

    /** @test */
    public function index_includes_a_freshly_created_zero_role_administrator(): void
    {
        $freshAdmin = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'fresh@example.com']);

        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/administrators');

        $response->assertStatus(200);
        $ids = collect($response->json('administrators'))->pluck('id');
        $this->assertTrue($ids->contains($freshAdmin->id));
    }

    /** @test */
    public function index_excludes_a_non_admin_user_type_with_zero_roles(): void
    {
        $becActive = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'email' => 'bec@example.com']);

        $response = $this->actingAs($this->rootAdmin)->getJson('/api/admin/administrators');

        $response->assertStatus(200);
        $ids = collect($response->json('administrators'))->pluck('id');
        $this->assertFalse($ids->contains($becActive->id));
    }

    /** @test */
    public function index_requires_adm_read_admins_permission(): void
    {
        $response = $this->actingAs($this->noPermUser())->getJson('/api/admin/administrators');

        $response->assertStatus(403);
    }

    // ── show() ───────────────────────────────────────────────────────────

    /** @test */
    public function show_returns_404_for_an_admin_type_account_holding_a_non_administration_role(): void
    {
        $rootCampus = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'rootcampus2@example.com']);
        $rootCampus->assignRole('ROOT_CAMPUS');

        $response = $this->actingAs($this->rootAdmin)->getJson("/api/admin/administrators/{$rootCampus->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function show_requires_adm_read_admins_permission(): void
    {
        $freshAdmin = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'fresh2@example.com']);

        $response = $this->actingAs($this->noPermUser())->getJson("/api/admin/administrators/{$freshAdmin->id}");

        $response->assertStatus(403);
    }

    // ── assignRole() — PR3b ──────────────────────────────────────────────────

    /**
     * A second ADMINISTRATION-type role, distinct from ROOT_ADMINISTRATION,
     * used to exercise a real (non-seeded) role assignment.
     */
    private function controlViewerRole(): Role
    {
        return Role::create(['name' => 'CONTROL_VIEWER', 'type' => 'ADMINISTRATION']);
    }

    /** @test */
    public function assign_role_rejects_a_role_that_is_not_administration_type(): void
    {
        $target = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'target1@example.com']);
        $rootCampusRole = Role::where('name', 'ROOT_CAMPUS')->first();

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/administrators/{$target->id}/role",
            ['role_id' => $rootCampusRole->id]
        );

        $response->assertStatus(422);
        $target->refresh();
        $this->assertCount(0, $target->roles);
    }

    /** @test */
    public function assign_role_rejects_a_nonexistent_role_id(): void
    {
        $target = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'target1b@example.com']);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/administrators/{$target->id}/role",
            ['role_id' => 999999]
        );

        $response->assertStatus(422);
        $target->refresh();
        $this->assertCount(0, $target->roles);
    }

    /** @test */
    public function assign_role_succeeds_with_a_valid_administration_type_role_on_a_different_account(): void
    {
        $target = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'target2@example.com']);
        $role = $this->controlViewerRole();

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/administrators/{$target->id}/role",
            ['role_id' => $role->id]
        );

        $response->assertStatus(200);
        $target->refresh();
        $this->assertCount(1, $target->roles);
        $this->assertSame('CONTROL_VIEWER', $target->roles->first()->name);
    }

    /** @test */
    public function assign_role_blocks_self_targeted_change_when_acting_user_holds_an_administration_role(): void
    {
        $role = $this->controlViewerRole();

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/administrators/{$this->rootAdmin->id}/role",
            ['role_id' => $role->id]
        );

        $response->assertStatus(422);
        $this->rootAdmin->refresh();
        $this->assertTrue($this->rootAdmin->hasRole('ROOT_ADMINISTRATION'));
        $this->assertCount(1, $this->rootAdmin->roles);
    }

    /** @test */
    public function assign_role_allows_changing_a_different_admins_role_even_when_role_name_matches_acting_users_role(): void
    {
        $target = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'target3@example.com']);
        $target->assignRole('ROOT_ADMINISTRATION');

        $viewerRole = $this->controlViewerRole();

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/administrators/{$target->id}/role",
            ['role_id' => $viewerRole->id]
        );

        $response->assertStatus(200);
        $target->refresh();
        $this->assertCount(1, $target->roles);
        $this->assertSame('CONTROL_VIEWER', $target->roles->first()->name);
        // Acting user's own role assignment must remain untouched.
        $this->rootAdmin->refresh();
        $this->assertTrue($this->rootAdmin->hasRole('ROOT_ADMINISTRATION'));
    }

    /** @test */
    public function assign_role_requires_adm_manage_admins_permission(): void
    {
        $target = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'target4@example.com']);
        $role = $this->controlViewerRole();

        $response = $this->actingAs($this->noPermUser())->putJson(
            "/api/admin/administrators/{$target->id}/role",
            ['role_id' => $role->id]
        );

        $response->assertStatus(403);
        $target->refresh();
        $this->assertCount(0, $target->roles);
    }

    // ── R6 — no DELETE route exists for administrators or administration-roles ──

    /** @test */
    public function no_delete_route_exists_for_administrators_or_administration_roles(): void
    {
        $target = User::factory()->create(['user_type' => 'ADMIN', 'email' => 'target5@example.com']);
        $role = $this->controlViewerRole();

        $adminsResponse = $this->actingAs($this->rootAdmin)->deleteJson("/api/admin/administrators/{$target->id}");
        $rolesResponse = $this->actingAs($this->rootAdmin)->deleteJson("/api/admin/administration-roles/{$role->id}");

        // 405 (Method Not Allowed) is Laravel's proof that the URI pattern is
        // registered (matches the existing GET {id} route) but no DELETE
        // handler exists for it — a stronger confirmation than a bare 404
        // would be, since it shows the framework actively rejects the verb.
        $adminsResponse->assertStatus(405);
        $rolesResponse->assertStatus(405);
    }
}
