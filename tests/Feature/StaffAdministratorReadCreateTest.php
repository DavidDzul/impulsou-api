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
 * Staff administrators read + create: api/admin/staff-administrators.
 * Gate ps_permission:PS_MANAGE_ADMINS (ROOT bypass only).
 */
class StaffAdministratorReadCreateTest extends TestCase
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

    private function actor(string $role): User
    {
        $user = $this->makeUser($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function staffRole(string $name = 'AUX_STAFF'): Role
    {
        return Role::forceCreate(['name' => $name, 'type' => 'ADMIN', 'guard_name' => 'web']);
    }

    private function validPayload(int $roleId, array $override = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Lopez',
            'email' => 'ana.staff@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'campus' => 'MERIDA',
            'role_id' => $roleId,
        ], $override);
    }

    // ---- gate ----

    public function test_guest_gets_401(): void
    {
        $this->getJson(self::BASE)->assertStatus(401);
        $this->getJson(self::BASE . '/1')->assertStatus(401);
        $this->postJson(self::BASE, [])->assertStatus(401);
    }

    public function test_non_root_actors_get_403_everywhere(): void
    {
        foreach (['ROOT_JOB', 'ROOT_CAMPUS', 'ADMIN_STUDENT', 'ROOT_ADMINISTRATION'] as $role) {
            $this->actor($role);
            $this->getJson(self::BASE)->assertStatus(403);
            $this->getJson(self::BASE . '/1')->assertStatus(403);
            $this->postJson(self::BASE, [])->assertStatus(403);
        }
        Sanctum::actingAs($this->makeUser(null, 'BUSINESS'));
        $this->getJson(self::BASE)->assertStatus(403);
    }

    public function test_root_passes_even_when_manage_permission_is_revoked(): void
    {
        Role::where('name', 'ROOT')->firstOrFail()->revokePermissionTo('PS_MANAGE_ADMINS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor('ROOT');

        $this->getJson(self::BASE)->assertOk();
    }

    // ---- index ----

    public function test_index_lists_only_in_scope_users_with_flags(): void
    {
        $root = $this->actor('ROOT');
        $staff = $this->makeUser('ROOT_JOB', 'ADMIN', ['email' => 'job@example.com']);
        $zero = $this->makeUser(null, 'ADMIN', ['email' => 'zero@example.com']);
        $obsolete = $this->makeUser('YUCATAN', 'ADMIN', ['email' => 'yuc@example.com']);
        $adm = $this->makeUser('ROOT_ADMINISTRATION', 'ADMIN', ['email' => 'adm@example.com']);
        $business = $this->makeUser(null, 'BUSINESS', ['email' => 'biz@example.com']);
        $becario = $this->makeUser(null, 'BEC_ACTIVE', ['email' => 'bec@example.com']);

        $response = $this->getJson(self::BASE)->assertOk()->assertJsonPath('res', true);
        $rows = collect($response->json('administrators'))->keyBy('id');

        foreach ([$adm, $business, $becario] as $excluded) {
            $this->assertFalse($rows->has($excluded->id), "user {$excluded->email} must be excluded");
        }
        foreach ([$root, $staff, $zero, $obsolete] as $included) {
            $this->assertTrue($rows->has($included->id), "user {$included->email} must be listed");
        }

        $this->assertTrue($rows[$root->id]['is_root']);
        $this->assertFalse($rows[$root->id]['is_editable']);
        $this->assertFalse($rows[$staff->id]['is_root']);
        $this->assertTrue($rows[$staff->id]['is_editable']);
        $this->assertSame('ROOT_JOB', $rows[$staff->id]['role']['name']);
        $this->assertFalse($rows[$staff->id]['role']['is_obsolete']);
        $this->assertNull($rows[$zero->id]['role']);
        $this->assertTrue($rows[$obsolete->id]['role']['is_obsolete']);
        $this->assertEqualsCanonicalizing(
            ['id', 'first_name', 'last_name', 'email', 'campus', 'active', 'role', 'is_root', 'is_editable'],
            array_keys($rows[$staff->id])
        );
        $this->assertEqualsCanonicalizing(['id', 'name', 'is_obsolete'], array_keys($rows[$staff->id]['role']));
    }

    public function test_index_excludes_holders_of_administration_roles_even_with_a_staff_role(): void
    {
        $this->actor('ROOT');
        $both = $this->makeUser('ROOT_JOB', 'ADMIN', ['email' => 'both@example.com']);
        $both->assignRole('ROOT_ADMINISTRATION');

        $ids = collect($this->getJson(self::BASE)->assertOk()->json('administrators'))->pluck('id');

        $this->assertNotContains($both->id, $ids);
    }

    // ---- show ----

    public function test_show_returns_in_scope_user_and_404_for_everything_else(): void
    {
        $this->actor('ROOT');
        $staff = $this->makeUser('ROOT_JOB');
        $this->getJson(self::BASE . "/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('res', true)
            ->assertJsonPath('administrator.id', $staff->id)
            ->assertJsonPath('administrator.role.name', 'ROOT_JOB');

        $outOfScope = [
            $this->makeUser('ROOT_ADMINISTRATION')->id,
            $this->makeUser(null, 'BUSINESS')->id,
            $this->makeUser(null, 'BEC_ACTIVE')->id,
            999999,
        ];
        foreach ($outOfScope as $id) {
            $this->getJson(self::BASE . "/$id")->assertStatus(404);
        }
        $this->getJson(self::BASE . '/1abc')->assertStatus(404);
    }

    // ---- store ----

    public function test_root_creates_an_admin_account_with_exactly_one_role(): void
    {
        $this->actor('ROOT');
        $role = $this->staffRole();

        $response = $this->postJson(self::BASE, $this->validPayload($role->id))
            ->assertStatus(201)
            ->assertJsonPath('res', true)
            ->assertJsonPath('administrator.email', 'ana.staff@example.com')
            ->assertJsonPath('administrator.role.name', 'AUX_STAFF')
            ->assertJsonPath('administrator.active', true)
            ->assertJsonPath('administrator.is_editable', true);

        $this->assertStringNotContainsString('secret123', $response->getContent());
        $this->assertArrayNotHasKey('password', $response->json('administrator'));

        $user = User::where('email', 'ana.staff@example.com')->firstOrFail();
        $this->assertSame('ADMIN', $user->user_type);
        $this->assertTrue($user->active);
        $this->assertSame('', $user->phone);
        $this->assertSame(['AUX_STAFF'], $user->roles()->pluck('name')->all());
        $this->assertNotSame('secret123', $user->password);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_created_user_can_log_in_with_the_given_password(): void
    {
        $this->actor('ROOT');
        $role = $this->staffRole();
        $this->postJson(self::BASE, $this->validPayload($role->id))->assertStatus(201);

        $this->app['auth']->forgetGuards();
        $this->postJson('api/admin/login', ['email' => 'ana.staff@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('res', true);
    }

    public function test_store_validation_failures_create_nothing(): void
    {
        $this->actor('ROOT');
        $role = $this->staffRole();
        $this->makeUser(null, 'ADMIN', ['email' => 'taken@example.com']);
        $before = User::count();

        $cases = [
            'duplicate email' => ['email' => 'taken@example.com'],
            'unconfirmed password' => ['password_confirmation' => 'other-pass'],
            'missing confirmation' => ['password_confirmation' => null],
            'bad campus' => ['campus' => 'CANCUN'],
            'missing role' => ['role_id' => null],
            'nonexistent role' => ['role_id' => 999999],
            'ROOT role' => ['role_id' => Role::where('name', 'ROOT')->value('id')],
            'YUCATAN role' => ['role_id' => Role::where('name', 'YUCATAN')->value('id')],
            'ADMINISTRATION role' => ['role_id' => Role::where('name', 'ROOT_ADMINISTRATION')->value('id')],
            'USER role' => ['role_id' => Role::where('name', 'GOLD')->value('id')],
            'sanctum role' => ['role_id' => Role::forceCreate(['name' => 'Auxiliar', 'type' => 'ADMIN', 'guard_name' => 'sanctum'])->id],
        ];

        foreach ($cases as $label => $override) {
            $this->postJson(self::BASE, $this->validPayload($role->id, $override))
                ->assertStatus(422, "case: $label");
        }
        $this->assertSame($before, User::count());
    }
}
