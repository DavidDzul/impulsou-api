<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        $users = User::where('admin', false)->get();
        return response()->json($users);
    }

    public function getPermissions(Request $request)
    {
        $user = $request->user();
        $permissions = $user->getAllPermissions();

        return response()->json([
            'permissions' => $permissions->pluck('name')
        ]);
    }
}