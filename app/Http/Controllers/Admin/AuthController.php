<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\UserAccess;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(UserAccess $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'msg' => 'Las credenciales son incorrectas.',
            ]);
        }

        if ($user->user_type !== 'ADMIN') {
            return response()->json([
                'res' => false,
                'msg' => 'Acceso denegado. Este usuario no tiene permisos de administrador.',
            ], 403);
        }

        $token = $user->createToken($request->email)->plainTextToken;

        return response()->json([
            "res" => true,
            "token" => $token,
            'usuario' => $user
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json([
            "res" => true,
            "msg" => "Token eliminado con éxito"
        ], 200);
    }

    public function getPermissions(Request $request)
    {
        $user = $request->user();
        $permissions = $user->getAllPermissions();

        return response()->json([
            'permissions' => $permissions->pluck('name')
        ]);
    }

    public function updateProfile(Request $request)
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
