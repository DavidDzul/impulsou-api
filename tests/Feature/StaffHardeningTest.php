<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * administradores-psicol PR4b: access hardening (ROOT-only staff management,
 * active check on the admin group, login throttle, password policy, unique race).
 */
class StaffHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(string $role, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['user_type' => 'ADMIN', 'campus' => 'MERIDA', 'active' => true], $attrs));
        $user->assignRole($role);

        return $user;
    }

    private function staffRoutes(User $target): array
    {
        return [
            ['getJson', 'api/admin/staff-roles'],
            ['getJson', 'api/admin/staff-roles/permissions'],
            ['postJson', 'api/admin/staff-roles', ['name' => 'NEW_ROLE']],
            ['putJson', 'api/admin/staff-roles/1/permissions', ['permissions_ids' => []]],
            ['getJson', 'api/admin/staff-administrators'],
            ['getJson', "api/admin/staff-administrators/{$target->id}"],
            ['postJson', 'api/admin/staff-administrators', []],
            ['putJson', "api/admin/staff-administrators/{$target->id}/role", []],
            ['patchJson', "api/admin/staff-administrators/{$target->id}/active", ['active' => false]],
            ['putJson', "api/admin/staff-administrators/{$target->id}/password", []],
        ];
    }

    public function test_non_root_holding_manage_permission_via_role_is_forbidden_on_every_staff_route(): void
    {
        $role = Role::create(['name' => 'SNEAKY', 'type' => 'ADMIN']);
        $role->givePermissionTo('PS_MANAGE_ADMINS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor = $this->admin('SNEAKY');
        $target = $this->admin('ROOT_JOB');
        Sanctum::actingAs($actor);

        foreach ($this->staffRoutes($target) as $route) {
            $method = $route[0];
            $uri = $route[1];
            $this->{$method}($uri, $route[2] ?? [])->assertStatus(403, "route: $uri");
        }
    }

    public function test_root_still_passes_with_manage_permission_revoked(): void
    {
        Role::where('name', 'ROOT')->firstOrFail()->revokePermissionTo('PS_MANAGE_ADMINS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->admin('ROOT'));

        $this->getJson('api/admin/staff-roles')->assertOk();
        $this->getJson('api/admin/staff-administrators')->assertOk();
    }

    public function test_deactivated_user_with_a_valid_token_is_rejected_with_401(): void
    {
        $user = $this->admin('ROOT');
        $plain = $user->createToken('t')->plainTextToken;
        $user->forceFill(['active' => false])->save();

        foreach (['api/admin/permissions', 'api/admin/admin', 'api/admin/staff-roles'] as $uri) {
            $this->withHeader('Authorization', "Bearer $plain")->getJson($uri)
                ->assertStatus(401)
                ->assertJsonPath('msg', 'Tu cuenta está desactivada.');
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_active_user_passes_the_active_check(): void
    {
        Sanctum::actingAs($this->admin('ROOT'));

        $this->getJson('api/admin/permissions')->assertOk();
    }

    public function test_login_endpoint_is_unaffected_by_the_active_middleware(): void
    {
        $user = $this->admin('ROOT');

        $this->postJson('api/admin/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_login_is_throttled_per_email_and_ip(): void
    {
        $user = $this->admin('ROOT');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('api/admin/login', ['email' => $user->email, 'password' => 'bad'])->assertStatus(422);
        }

        $this->postJson('api/admin/login', ['email' => $user->email, 'password' => 'bad'])->assertStatus(429);
        // Same account (case-insensitive): even the right password is throttled; another email is not.
        $this->postJson('api/admin/login', ['email' => strtoupper($user->email), 'password' => 'password'])->assertStatus(429);
        $other = $this->admin('ROOT');
        $this->postJson('api/admin/login', ['email' => $other->email, 'password' => 'password'])->assertOk();
    }
}
