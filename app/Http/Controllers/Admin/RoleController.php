<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::with(['configuration', 'permissions'])->get();

        return response()->json([
            'res' => true,
            'roles' => $roles
        ], 200);
    }
}