<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Illuminate\Http\Request;
use App\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function index(Request $request)
    {

        $type = $request->query('type', 'USER');

        $roles = Role::where('type', $type)->with(['configuration', 'permissions'])->get();

        return response()->json([
            'res' => true,
            'roles' => $roles
        ], 200);
    }

    public function getAllPermissions()
    {
        $permissions = Permission::orderBy('name', 'asc')->get();
        return response()->json([
            'res' => true,
            'permissions' => $permissions
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);
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
