<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Staff administrators writes: PUT role, PATCH active, PUT password (api/admin/staff-administrators/{id}/...).
 * Guard order: gate -> self 403 -> scope 404 -> ROOT target 403 -> validation 422.
 */
class StaffAdministratorWriteGuardsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'api/admin/staff-administrators';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeUser(?string $role, string $type = 'ADMIN', array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(
            ['user_type' => $type, 'campus' => 'MERIDA', 'active' => true], $attrs
        ));
        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function actor(string $role = 'ROOT'): User
    {
        $user = $this->makeUser($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function staffRole(string $name = 'AUX_STAFF'): Role
    {
        return Role::forceCreate(['name' => $name, 'type' => 'ADMIN', 'guard_name' => 'web']);
    }

    /** Each endpoint: [method, suffix]. */
    private function endpoints(): array
    {
        return [
            'role' => ['putJson', 'role'],
            'active' => ['patchJson', 'active'],
            'password' => ['putJson', 'password'],
        ];
    }

    private function assertOldTokenRejected(string $plain): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $plain")->getJson('api/admin/staff-roles')->assertStatus(401);
        $this->app['auth']->forgetGuards();
    }

    // ---- gate ----

    public function test_guest_gets_401(): void
    {
        foreach ($this->endpoints() as [$method, $suffix]) {
            $this->{$method}(self::BASE . "/1/$suffix", [])->assertStatus(401);
        }
    }

    public function test_non_root_actors_get_403(): void
    {
        $target = $this->makeUser('ROOT_JOB');
        foreach (['ROOT_JOB', 'ROOT_CAMPUS', 'ADMIN_STUDENT', 'ROOT_ADMINISTRATION'] as $role) {
            $this->actor($role);
            foreach ($this->endpoints() as [$method, $suffix]) {
                $this->{$method}(self::BASE . "/{$target->id}/$suffix", [])->assertStatus(403);
            }
        }
        Sanctum::actingAs($this->makeUser(null, 'BUSINESS'));
        $this->putJson(self::BASE . "/{$target->id}/role", [])->assertStatus(403);
    }

    public function test_root_passes_even_when_manage_permission_is_revoked(): void
    {
        Role::where('name', 'ROOT')->firstOrFail()->revokePermissionTo('PS_MANAGE_ADMINS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor();
        $target = $this->makeUser('ROOT_JOB');

        $this->patchJson(self::BASE . "/{$target->id}/active", ['active' => false])->assertOk();
    }

    // ---- guard matrix ----

    public function test_self_target_gets_403_even_with_invalid_payload(): void
    {
        $root = $this->actor();
        foreach ($this->endpoints() as [$method, $suffix]) {
            $this->{$method}(self::BASE . "/{$root->id}/$suffix", ['junk' => 1])->assertStatus(403);
        }
        $this->assertTrue($root->fresh()->hasRole('ROOT'));
        $this->assertTrue((bool) $root->fresh()->active);
    }

    public function test_root_target_gets_403_even_with_invalid_payload(): void
    {
        $this->actor();
        $otherRoot = $this->makeUser('ROOT');
        foreach ($this->endpoints() as [$method, $suffix]) {
            $this->{$method}(self::BASE . "/{$otherRoot->id}/$suffix", ['junk' => 1])->assertStatus(403);
        }
        $this->assertTrue($otherRoot->fresh()->hasRole('ROOT'));
    }

    public function test_out_of_scope_targets_get_404_even_with_invalid_payload(): void
    {
        $this->actor();
        $ids = [
            $this->makeUser('ROOT_ADMINISTRATION')->id,
            $this->makeUser(null, 'BUSINESS')->id,
            $this->makeUser(null, 'BEC_ACTIVE')->id,
            999999,
            '1abc',
        ];
        foreach ($ids as $id) {
            foreach ($this->endpoints() as [$method, $suffix]) {
                $this->{$method}(self::BASE . "/$id/$suffix", ['junk' => 1])->assertStatus(404);
            }
        }
    }

    // ---- role ----

    public function test_role_change_replaces_role_and_revokes_tokens(): void
    {
        $this->actor();
        $target = $this->makeUser('ROOT_JOB');
        $new = $this->staffRole();
        $target->createToken('t');

        $this->putJson(self::BASE . "/{$target->id}/role", ['role_id' => $new->id])
            ->assertOk()
            ->assertJsonPath('res', true)
            ->assertJsonPath('administrator.id', $target->id)
            ->assertJsonPath('administrator.role.name', 'AUX_STAFF');

        $target = $target->fresh();
        $this->assertSame(['AUX_STAFF'], $target->roles->pluck('name')->all());
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_zero_role_and_obsolete_yucatan_users_can_be_assigned_a_role(): void
    {
        $this->actor();
        $zero = $this->makeUser(null);
        $yuc = $this->makeUser('YUCATAN');
        $new = $this->staffRole();

        $this->putJson(self::BASE . "/{$zero->id}/role", ['role_id' => $new->id])->assertOk();
        $this->putJson(self::BASE . "/{$yuc->id}/role", ['role_id' => $new->id])->assertOk();

        $this->assertSame(['AUX_STAFF'], $zero->fresh()->roles->pluck('name')->all());
        $this->assertSame(['AUX_STAFF'], $yuc->fresh()->roles->pluck('name')->all());
    }

    public function test_role_change_to_non_assignable_roles_gets_422_and_changes_nothing(): void
    {
        $this->actor();
        $target = $this->makeUser('ROOT_JOB');
        $target->createToken('t');
        $sanctum = Role::forceCreate(['name' => 'AUX_SANCTUM', 'type' => 'ADMIN', 'guard_name' => 'sanctum']);
        $ids = Role::whereIn('name', ['ROOT', 'YUCATAN', 'ROOT_ADMINISTRATION', 'GOLD'])->pluck('id')
            ->push($sanctum->id)->push(999999)->push('abc')->push(null);

        foreach ($ids as $id) {
            $this->putJson(self::BASE . "/{$target->id}/role", ['role_id' => $id])->assertStatus(422);
        }
        $this->putJson(self::BASE . "/{$target->id}/role", [])->assertStatus(422);

        $this->assertSame(['ROOT_JOB'], $target->fresh()->roles->pluck('name')->all());
        $this->assertSame(1, $target->tokens()->count());
    }

    public function test_old_token_is_rejected_after_role_change(): void
    {
        $this->actor();
        $target = $this->makeUser('ROOT_JOB');
        $plain = $target->createToken('t')->plainTextToken;
        $this->putJson(self::BASE . "/{$target->id}/role", ['role_id' => $this->staffRole()->id])->assertOk();

        $this->assertOldTokenRejected($plain);
    }

    // ---- active ----

    public function test_deactivate_revokes_tokens_and_blocks_login_and_reactivate_restores_login(): void
    {
        $root = $this->actor();
        $target = $this->makeUser('ROOT_JOB', 'ADMIN', ['password' => Hash::make('secret123')]);
        $plain = $target->createToken('t')->plainTextToken;

        $this->patchJson(self::BASE . "/{$target->id}/active", ['active' => false])
            ->assertOk()
            ->assertJsonPath('administrator.active', false);
        $this->assertSame(0, $target->tokens()->count());

        $this->assertOldTokenRejected($plain);
        $this->postJson('api/admin/login', ['email' => $target->email, 'password' => 'secret123'])->assertStatus(422);

        Sanctum::actingAs($root);
        $this->patchJson(self::BASE . "/{$target->id}/active", ['active' => true])
            ->assertOk()->assertJsonPath('administrator.active', true);
        $this->assertSame(0, $target->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->postJson('api/admin/login', ['email' => $target->email, 'password' => 'secret123'])->assertOk();
    }

    public function test_active_requires_boolean(): void
    {
        $this->actor();
        $target = $this->makeUser('ROOT_JOB');
        foreach ([[], ['active' => 'maybe'], ['active' => null]] as $payload) {
            $this->patchJson(self::BASE . "/{$target->id}/active", $payload)->assertStatus(422);
        }
        $this->assertTrue((bool) $target->fresh()->active);
    }

    // ---- password ----

    public function test_password_reset_hashes_revokes_tokens_and_never_echoes(): void
    {
        $this->actor();
        $target = $this->makeUser('ROOT_JOB', 'ADMIN', ['password' => Hash::make('oldpass123')]);
        $plain = $target->createToken('t')->plainTextToken;

        $response = $this->putJson(self::BASE . "/{$target->id}/password", [
            'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk()->assertJsonPath('res', true)->assertJsonStructure(['res', 'msg']);
        $this->assertStringNotContainsString('newpass123', $response->getContent());

        $this->assertSame(0, $target->tokens()->count());
        $this->assertTrue(Hash::check('newpass123', $target->fresh()->password));

        $this->assertOldTokenRejected($plain);
        $this->postJson('api/admin/login', ['email' => $target->email, 'password' => 'oldpass123'])->assertStatus(422);
        $this->postJson('api/admin/login', ['email' => $target->email, 'password' => 'newpass123'])->assertOk();
    }

    public function test_password_reset_validation_failures_change_nothing(): void
    {
        $this->actor();
        $target = $this->makeUser('ROOT_JOB', 'ADMIN', ['password' => Hash::make('oldpass123')]);
        $target->createToken('t');
        $cases = [
            [],
            ['password' => 'newpass123'],
            ['password' => 'newpass123', 'password_confirmation' => 'different1'],
        ];
        foreach ($cases as $payload) {
            $this->putJson(self::BASE . "/{$target->id}/password", $payload)->assertStatus(422);
        }
        $this->assertTrue(Hash::check('oldpass123', $target->fresh()->password));
        $this->assertSame(1, $target->tokens()->count());
    }
}
