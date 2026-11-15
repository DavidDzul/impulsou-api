<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Request;
use App\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::whereIn('type', Role::MANAGEABLE_TYPES)->with(['configuration', 'permissions'])->get();

        return response()->json([
            'res' => true,
            'roles' => $roles
        ], 200);
    }

    public function getAllPermissions()
    {
        $permissions = Permission::whereIn('type', Role::MANAGEABLE_TYPES)->orderBy('name', 'asc')->get();

        return response()->json([
            'res' => true,
            'permissions' => $permissions
        ], 200);
    }

    public function update(Request $request, $id)
    {
        // Only business plan (USER) roles; staff/ADMINISTRATION ids are 404.
        // find() + abort, not findOrFail: Handler maps ModelNotFoundException to 400.
        $role = Role::whereIn('type', Role::MANAGEABLE_TYPES)->find($id);
        abort_if($role === null, 404);
        abort_if(in_array($role->name, Role::PROTECTED_ROLES, true), 403);

        $data = $request->validate(Role::createOrUpdateRules());

        $role->configuration()->updateOrCreate(
            ['role_id' => $role->id],
            $request->only([
                'unlimited_jobs',
                'num_job_vacancies',
                'unlimited_professionals',
                'num_professional_vacancies',
                'unlimited_jr',
                'num_jr_vacancies',
                'unlimited_visualizations',
                'num_visualizations'
            ])
        );

        $role->syncPermissions($data['permissions_ids'] ?? []);

        return new RoleResource($role->load(['configuration', 'permissions']));
    }
}
