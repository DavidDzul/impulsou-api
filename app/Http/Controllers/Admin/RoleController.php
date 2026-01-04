<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $data = $request->validate(Role::updateRules());


        $role->configuration()->updateOrCreate(
            ['role_id' => $role->id],
            [
                'num_visualizations' => $data['num_visualizations'],
                'num_vacancies'      => $data['num_vacancies'],
                'unlimited'          => $data['unlimited'] ?? false,
            ]
        );

        $role->syncPermissions($data['permissions_ids'] ?? []);

        return response()->json([
            'res' => true,
            'data' => $role->load(['configuration', 'permissions'])
        ]);
    }
}
