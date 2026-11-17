<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * administradores-psicol PR1: PS_MANAGE_ADMINS gates the staff admin/role management
 * endpoints. ROOT only, never grantable. Its module/description copy lives in the DB like every PS_*.
 */
class RoleSeederStaffManagePermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function ps_manage_admins_is_seeded_as_admin_web_permission_with_copy(): void
    {
        $this->seed(RoleSeeder::class);

        $permission = Permission::where('name', 'PS_MANAGE_ADMINS')->first();

        $this->assertNotNull($permission);
        $this->assertSame('ADMIN', $permission->type);
        $this->assertSame('web', $permission->guard_name);
        $this->assertSame('Configuración', $permission->module);
        $this->assertSame('Administrar administradores y roles del personal (solo ROOT)', $permission->description);
    }

    /** @test */
    public function ps_manage_admins_is_held_by_root_only(): void
    {
        $this->seed(RoleSeeder::class);

        $permission = Permission::where('name', 'PS_MANAGE_ADMINS')->firstOrFail();

        $this->assertSame(['ROOT'], $permission->roles()->pluck('name')->all());
        $this->assertSame(0, DB::table('model_has_permissions')->where('permission_id', $permission->id)->count());
    }
}
