<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\PsPermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff (type ADMIN, guard web) roles managed from psicol-panel's "Administradores" page.
 * Gate: ps_permission:PS_MANAGE_ADMINS (ROOT only). ROOT and YUCATAN are never exposed.
 */
class StaffRoleController extends Controller
{
    public function index()
    {
        $roles = Role::where('type', 'ADMIN')
            ->where('guard_name', 'web')
            ->whereNotIn('name', Role::STAFF_NON_ASSIGNABLE)
            ->with('permissions:id')
            ->orderBy('name')
            ->get();

        // Counted from the pivot: Spatie's users() relation cannot be resolved by withCount().
        $usersCount = DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', (new User())->getMorphClass())
            ->whereIn('role_id', $roles->pluck('id'))
            ->selectRaw('role_id, COUNT(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        return response()->json([
            'res' => true,
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'type' => $role->type,
                'permissions_ids' => $role->permissions->pluck('id')->values()->all(),
                'users_count' => (int) ($usersCount[$role->id] ?? 0),
            ])->values(),
        ]);
    }

    public function permissions()
    {
        return response()->json(['res' => true, 'modules' => PsPermissionCatalog::grouped()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(Role::createStaffRules());

        // Type and guard are never client-controlled.
        $role = Role::forceCreate(['name' => $data['name'], 'type' => 'ADMIN', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['res' => true, 'role' => $this->present($role, 0)], 201);
    }

    public function updatePermissions(Request $request, $id)
    {
        // Scope first (404), validation after: never leak existence through a 422.
        $role = Role::find($id);
        abort_if(
            $role === null
                || $role->type !== 'ADMIN'
                || $role->guard_name !== 'web'
                || in_array($role->name, Role::STAFF_NON_ASSIGNABLE, true),
            404,
            'Rol no encontrado.'
        );

        $data = $request->validate(Role::syncStaffPermissionsRules());

        $role->syncPermissions(array_values(array_unique($data['permissions_ids'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['res' => true, 'role' => $this->present($role->fresh('permissions'))]);
    }

    private function present(Role $role, ?int $usersCount = null): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'type' => $role->type,
            'permissions_ids' => $role->permissions->pluck('id')->values()->all(),
            'users_count' => $usersCount ?? DB::table(config('permission.table_names.model_has_roles'))
                ->where('model_type', (new User())->getMorphClass())
                ->where('role_id', $role->id)
                ->count(),
        ];
    }
}
