<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

        if ($request->filled('password')) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return response()->json([
            'res' => true,
            'msg' => 'Usuario actualizado correctamente',
            'user' => $user
        ], 200);
    }
}
