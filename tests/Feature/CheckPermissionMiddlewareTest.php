<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Isolates the fix documented in App\Http\Middleware\CheckPermission's
 * docblock: Spatie\Permission\Middleware\PermissionMiddleware (registered
 * under the `permission` Kernel alias per design D4) does not recognize
 * permissions granted via a ROLE in this spatie/laravel-permission version
 * — only permissions granted DIRECTLY to a user work with Spatie's own
 * middleware. This test proves our replacement handles BOTH cases
 * correctly, using a throwaway route bound directly to the alias so the
 * assertion is not coupled to any specific feature's routes/controllers.
 */
class CheckPermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'permission:SOME_TEST_PERMISSION'])
            ->get('/__test/permission-gate', fn () => response()->json(['res' => true]));
    }

    /** @test */
    public function role_granted_permission_is_recognized(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $user->assignRole('ROOT_ADMINISTRATION');

        // Grant the permission to the ROLE (not directly to the user) —
        // isolates the exact path that was broken in Spatie's own
        // PermissionMiddleware (see CheckPermission's docblock).
        $role = Role::where('name', 'ROOT_ADMINISTRATION')->first();
        Permission::firstOrCreate(['name' => 'SOME_TEST_PERMISSION'])->syncRoles([$role]);

        $response = $this->actingAs($user)->getJson('/__test/permission-gate');

        $response->assertStatus(200);
    }

    /** @test */
    public function directly_granted_permission_is_recognized(): void
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        Permission::firstOrCreate(['name' => 'SOME_TEST_PERMISSION']);
        $user->givePermissionTo('SOME_TEST_PERMISSION');

        $response = $this->actingAs($user)->getJson('/__test/permission-gate');

        $response->assertStatus(200);
    }

    /** @test */
    public function missing_permission_is_rejected_with_403_not_500(): void
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $response = $this->actingAs($user)->getJson('/__test/permission-gate');

        $response->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_request_is_rejected_with_401(): void
    {
        $response = $this->getJson('/__test/permission-gate');

        $response->assertStatus(401);
    }
}
