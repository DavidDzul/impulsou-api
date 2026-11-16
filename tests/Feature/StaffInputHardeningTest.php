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
 * administradores-psicol PR4b: input hardening (free staff passwords, unique race, bail rules).
 */
class StaffInputHardeningTest extends TestCase
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


    public function test_staff_passwords_have_no_minimum_length(): void
    {
        Sanctum::actingAs($this->admin('ROOT'));
        $role = Role::forceCreate(['name' => 'AUXILIAR', 'type' => 'ADMIN', 'guard_name' => 'web']);

        $this->postJson('api/admin/staff-administrators', [
            'first_name' => 'Ana', 'last_name' => 'Perez', 'email' => 'ana@example.com',
            'password' => 'a', 'password_confirmation' => 'a', 'campus' => 'MERIDA', 'role_id' => $role->id,
        ])->assertStatus(201);

        $target = User::where('email', 'ana@example.com')->firstOrFail();
        $this->putJson("api/admin/staff-administrators/{$target->id}/password", [
            'password' => 'b', 'password_confirmation' => 'b',
        ])->assertOk();
        $this->assertTrue(Hash::check('b', $target->fresh()->password));
    }

    public function test_role_create_unique_race_returns_422(): void
    {
        Sanctum::actingAs($this->admin('ROOT'));
        // Simulates the race: the validation closure passes but the insert hits the unique key.
        Role::creating(function () {
            throw new QueryException('insert', [], new \Exception('Duplicate entry'));
        });

        $this->postJson('api/admin/staff-roles', ['name' => 'RACE_ROLE'])->assertStatus(422);
    }

    public function test_account_create_unique_race_returns_422(): void
    {
        Sanctum::actingAs($this->admin('ROOT'));
        $role = Role::forceCreate(['name' => 'AUXILIAR', 'type' => 'ADMIN', 'guard_name' => 'web']);
        User::creating(function () {
            throw new QueryException('insert', [], new \Exception('Duplicate entry'));
        });

        $this->postJson('api/admin/staff-administrators', [
            'first_name' => 'Ana', 'last_name' => 'Perez', 'email' => 'race@example.com',
            'password' => 'x', 'password_confirmation' => 'x', 'campus' => 'MERIDA', 'role_id' => $role->id,
        ])->assertStatus(422);
    }

    public function test_bail_stops_at_first_failing_rule_for_role_id_and_permission_ids(): void
    {
        Sanctum::actingAs($this->admin('ROOT'));
        $role = Role::forceCreate(['name' => 'AUXILIAR', 'type' => 'ADMIN', 'guard_name' => 'web']);

        $res = $this->putJson("api/admin/staff-roles/{$role->id}/permissions", ['permissions_ids' => ['abc']])
            ->assertStatus(422);
        $this->assertCount(1, $res->json('errors')['permissions_ids.0']);

        $res = $this->postJson('api/admin/staff-administrators', ['role_id' => 'abc'])->assertStatus(422);
        $this->assertCount(1, $res->json('errors')['role_id']);
    }
}
