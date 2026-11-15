<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PsPermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Route::middleware(['api', 'auth:sanctum'])->prefix('__test')->group(function () {
            Route::get('ps-read', fn () => response()->json(['ok' => true]))
                ->middleware('ps_permission:PS_READ_BUSINESS');
            Route::get('ps-write', fn () => response()->json(['ok' => true]))
                ->middleware('ps_permission:PS_CREATE_BUSINESS|PS_EDIT_BUSINESS');
            Route::get('adm-only', fn () => response()->json(['ok' => true]))
                ->middleware('permission:ADM_READ_USERS');
            Route::get('ps-adm-adm', fn () => response()->json(['ok' => true]))
                ->middleware('ps_permission:ADM_READ_USERS');
            Route::get('mixed', fn () => response()->json(['ok' => true]))
                ->middleware('ps_permission:PS_READ_BUSINESS|ADM_READ_USERS');
            Route::get('root-only', fn () => response()->json(['ok' => true]))
                ->middleware('ps_root');
        });
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_root_passes_ps_gate_even_after_permission_is_removed_from_role(): void
    {
        Role::findByName('ROOT')->revokePermissionTo('PS_READ_BUSINESS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->staff('ROOT'));

        $this->getJson('/__test/ps-read')->assertOk();
    }

    public function test_root_is_not_bypassed_on_adm_gate(): void
    {
        Sanctum::actingAs($this->staff('ROOT'));

        $this->getJson('/__test/adm-only')->assertForbidden();
        $this->getJson('/__test/ps-adm-adm')->assertForbidden();
    }

    public function test_root_is_not_bypassed_on_mixed_list(): void
    {
        Role::findByName('ROOT')->revokePermissionTo('PS_READ_BUSINESS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->staff('ROOT'));

        $this->getJson('/__test/mixed')->assertForbidden();
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/__test/ps-read')->assertUnauthorized();
        $this->getJson('/__test/root-only')->assertUnauthorized();
    }

    public function test_non_root_without_permission_gets_403(): void
    {
        Role::findByName('ROOT_CAMPUS')->revokePermissionTo('PS_EDIT_BUSINESS');
        Role::findByName('ROOT_CAMPUS')->revokePermissionTo('PS_CREATE_BUSINESS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->staff('ROOT_CAMPUS'));

        $this->getJson('/__test/ps-write')->assertForbidden();
    }

    public function test_non_root_with_one_of_the_listed_permissions_passes(): void
    {
        Role::findByName('ROOT_CAMPUS')->revokePermissionTo('PS_EDIT_BUSINESS');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->staff('ROOT_CAMPUS'));

        $this->getJson('/__test/ps-write')->assertOk();
    }

    public function test_non_root_with_permission_passes(): void
    {
        Sanctum::actingAs($this->staff('ROOT_CAMPUS'));

        $this->getJson('/__test/ps-read')->assertOk();
    }

    public function test_root_administration_is_forbidden_on_ps_gate(): void
    {
        Sanctum::actingAs($this->staff('ROOT_ADMINISTRATION'));

        $this->getJson('/__test/ps-read')->assertForbidden();
    }

    public function test_ps_root_allows_root_only(): void
    {
        Sanctum::actingAs($this->staff('ROOT'));

        $this->getJson('/__test/root-only')->assertOk();
    }

    public function test_ps_root_rejects_root_job_even_with_ps_roles(): void
    {
        $user = $this->staff('ROOT_JOB');
        $user->givePermissionTo('PS_ROLES');
        Sanctum::actingAs($user);

        $this->getJson('/__test/root-only')->assertForbidden();
    }

    public function test_ps_root_rejects_other_staff_roles(): void
    {
        foreach (['ROOT_ADMINISTRATION', 'ROOT_CAMPUS', 'ADMIN_JOB', 'YUCATAN'] as $role) {
            Sanctum::actingAs($this->staff($role));

            $this->getJson('/__test/root-only')->assertForbidden();
        }
    }

    public function test_ps_root_does_not_depend_on_role_permissions(): void
    {
        Role::findByName('ROOT')->revokePermissionTo('PS_ROLES');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->staff('ROOT'));

        $this->getJson('/__test/root-only')->assertOk();
    }
}
