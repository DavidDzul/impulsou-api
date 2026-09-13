<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Control (Roles) — administration-panel's own role management, isolated
 * from psicol-panel's RoleController by `type='ADMINISTRATION'`.
 *
 * Every query/whitelist below is scoped to type='ADMINISTRATION' server-side
 * (design obs #1593, spec obs #1592 R2) — no method here may leak or accept
 * USER-type roles/permissions.
 */
class AdministrationRoleController extends Controller
{
    public function index()
    {
        $roles = Role::where('type', 'ADMINISTRATION')->with('permissions')->get();

        return response()->json(['res' => true, 'roles' => $roles]);
    }

    /**
     * Permissions catalog for the role-detail checklist. Deliberately does
     * NOT reuse RoleController::getAllPermissions(), which returns every
     * permission unfiltered (spec obs #1592 R2 explicit requirement).
     */
    public function permissionsCatalog()
    {
        $permissions = Permission::where('type', 'ADMINISTRATION')->orderBy('name')->get();

        return response()->json(['res' => true, 'permissions' => $permissions]);
    }

    public function show($id)
    {
        $role = Role::where('type', 'ADMINISTRATION')->with('permissions')->find($id);

        if (!$role) {
            return response()->json(['res' => false, 'msg' => 'Rol no encontrado.'], 404);
        }

        return response()->json(['res' => true, 'role' => $role]);
    }

    /**
     * `type` is never read from the request — it is always hardcoded to
     * 'ADMINISTRATION' here, even if the client submits a `type` field.
     */
    public function store(Request $request)
    {
        $data = $request->validate(Role::createAdministrationRules());

        $role = Role::create([
            'name' => $data['name'],
            'type' => 'ADMINISTRATION',
        ]);

        // index()/show()/syncPermissions() all eager-load 'permissions', so
        // every OTHER role read path always has this key present. A freshly
        // created role can never have any permissions yet — set the relation
        // directly (no need for a DB round trip) so the response shape
        // matches every other path exactly. Omitting this crashed the
        // frontend's permission-count column with "Cannot read properties of
        // undefined (reading 'length')" the moment a newly created role
        // reached the table without a page reload.
        $role->setRelation('permissions', collect());

        return response()->json(['res' => true, 'role' => $role], 201);
    }

    /**
     * Every submitted permission id must be a type='ADMINISTRATION'
     * permission (Role::syncPermissionsRules()). If any id fails, Laravel's
     * validate() throws before syncPermissions() runs, so the whole request
     * is rejected — no partial application.
     */
    public function syncPermissions(Request $request, $id)
    {
        $role = Role::where('type', 'ADMINISTRATION')->find($id);

        if (!$role) {
            return response()->json(['res' => false, 'msg' => 'Rol no encontrado.'], 404);
        }

        $data = $request->validate(Role::syncPermissionsRules());

        $role->syncPermissions($data['permissions_ids'] ?? []);

        return response()->json(['res' => true, 'role' => $role->load('permissions')]);
    }
}
