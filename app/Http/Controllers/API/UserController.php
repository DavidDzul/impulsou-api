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

    public function updateUser(Request $request)
    {
        $user = User::findOrFail($request->id);
        $data = $request->validate(User::updateRulesProfile($user->id));

        $user->fill($data)->save();

        return response()->json([
            'res' => true,
            'msg' => 'Usuario actualizado correctamente',
            'user' => $user
        ], 200);
    }
}
