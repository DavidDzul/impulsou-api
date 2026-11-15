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
 * Per-verb ps_permission gates on the persons routes. The gate runs before the scope lookup and
 * validation, so a non-holder gets 403 even when the target does not exist or is out of scope.
 * Allowed actors are asserted as "not 403" (what happens after the gate is covered elsewhere).
 */
class PersonsGateMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const READ_ROUTES = [
        'GET users' => ['GET', 'users'],
        'GET graduates' => ['GET', 'graduates'],
        'GET users/{id}' => ['GET', 'users/{person}'],
        'GET graduates/{id}' => ['GET', 'graduates/{person}'],
    ];

    private const WRITE_ROUTES = [
        'PUT users/{id}' => ['PUT', 'users/{person}'],
        'PATCH users/{id}' => ['PATCH', 'users/{person}'],
        'PUT graduates/{id}' => ['PUT', 'graduates/{person}'],
        'PATCH graduates/{id}' => ['PATCH', 'graduates/{person}'],
        'POST persons' => ['POST', 'persons'],
        'POST users' => ['POST', 'users'],
        'POST graduates' => ['POST', 'graduates'],
    ];

    private const PDF_ROUTE = ['PUT', 'users/{person}/fetchPDF'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'campus' => 'MERIDA', 'active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function hit(User $actor, array $route)
    {
        [$verb, $uri] = $route;
        $person = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        Sanctum::actingAs($actor);

        return $this->json($verb, '/api/admin/'.str_replace('{person}', (string) $person->id, $uri), []);
    }

    public function allRoutes(): array
    {
        return self::READ_ROUTES + self::WRITE_ROUTES + ['PUT users/{id}/fetchPDF' => self::PDF_ROUTE];
    }

    /** @dataProvider allRoutes */
    public function test_unauthenticated_gets_401(string $verb, string $uri): void
    {
        $this->json($verb, '/api/admin/'.str_replace('{person}', '1', $uri), [])->assertUnauthorized();
    }

    /** @dataProvider allRoutes */
    public function test_job_attendance_and_yucatan_roles_get_403(string $verb, string $uri): void
    {
        foreach (['ROOT_JOB', 'ADMIN_JOB', 'ATTENDANCE', 'YUCATAN'] as $role) {
            $this->hit($this->staff($role), [$verb, $uri])->assertForbidden();
        }
    }

    /** @dataProvider allRoutes */
    public function test_root_campus_and_admin_student_pass_the_gate(string $verb, string $uri): void
    {
        foreach (['ROOT_CAMPUS', 'ADMIN_STUDENT'] as $role) {
            $this->assertNotSame(403, $this->hit($this->staff($role), [$verb, $uri])->getStatusCode(), "$role");
        }
    }

    /** @dataProvider allRoutes */
    public function test_root_passes_even_with_every_person_permission_revoked(string $verb, string $uri): void
    {
        $root = Role::findByName('ROOT');
        foreach (['READ', 'CREATE', 'EDIT'] as $verb) {
            $root->revokePermissionTo(["PS_{$verb}_USERS", "PS_{$verb}_GRADUATES"]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNotSame(403, $this->hit($this->staff('ROOT'), [$verb, $uri])->getStatusCode());
    }

    public function readOnlyRoutes(): array
    {
        return self::READ_ROUTES;
    }

    /** @dataProvider readOnlyRoutes */
    public function test_root_administration_reads_with_adm_read_users(string $verb, string $uri): void
    {
        $this->hit($this->staff('ROOT_ADMINISTRATION'), [$verb, $uri])->assertOk();
    }

    public function writeAndPdfRoutes(): array
    {
        return self::WRITE_ROUTES + ['PUT users/{id}/fetchPDF' => self::PDF_ROUTE];
    }

    /** @dataProvider writeAndPdfRoutes */
    public function test_root_administration_cannot_write(string $verb, string $uri): void
    {
        $this->hit($this->staff('ROOT_ADMINISTRATION'), [$verb, $uri])->assertForbidden();
    }

    public function test_holder_of_only_read_graduates_can_read_users(): void
    {
        $role = Role::create(['name' => 'GRAD_READER', 'type' => 'ADMIN', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('PS_READ_GRADUATES'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNotSame(403, $this->hit($this->staff('GRAD_READER'), self::READ_ROUTES['GET users'])->getStatusCode());
    }

    public function test_create_gates_are_split_per_resource(): void
    {
        $role = Role::create(['name' => 'USER_CREATOR', 'type' => 'ADMIN', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findByName('PS_CREATE_USERS'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor = $this->staff('USER_CREATOR');

        $this->assertNotSame(403, $this->hit($actor, self::WRITE_ROUTES['POST users'])->getStatusCode());
        $this->assertNotSame(403, $this->hit($actor, self::WRITE_ROUTES['POST persons'])->getStatusCode());
        $this->hit($actor, self::WRITE_ROUTES['POST graduates'])->assertForbidden();
    }

    public function test_non_holder_targeting_a_staff_id_gets_403_not_404(): void
    {
        $root = $this->staff('ROOT');
        Sanctum::actingAs($this->staff('ROOT_JOB'));

        $this->getJson("/api/admin/users/{$root->id}")->assertForbidden();
        $this->putJson("/api/admin/users/{$root->id}", ['email' => 'x@example.test'])->assertForbidden();
    }

    public function test_filter_users_is_open_to_classes_and_read_users_holders_but_not_attendance(): void
    {
        $query = '/api/admin/filterUsers?campus=MERIDA&generation_id=1';

        foreach (['ROOT', 'ROOT_CAMPUS', 'ADMIN_STUDENT'] as $role) {
            Sanctum::actingAs($this->staff($role));
            $this->assertNotSame(403, $this->getJson($query)->getStatusCode(), $role);
        }

        foreach (['ATTENDANCE', 'ROOT_JOB', 'ADMIN_JOB', 'YUCATAN', 'ROOT_ADMINISTRATION'] as $role) {
            Sanctum::actingAs($this->staff($role));
            $this->getJson($query)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson($query)->assertUnauthorized();
    }

    public function test_filter_users_admin_student_gets_200(): void
    {
        Sanctum::actingAs($this->staff('ADMIN_STUDENT'));

        $this->getJson('/api/admin/filterUsers?campus=MERIDA&generation_id=1')->assertOk();
    }
}
