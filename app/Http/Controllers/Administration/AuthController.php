<?php

namespace App\Http\Controllers\Administration;

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

        if ($user->user_type !== 'ADMIN' || !$user->hasRole('ADMINISTRATION')) {
            return response()->json([
                'res' => false,
                'msg' => 'Acceso denegado. Este usuario no tiene acceso al panel de administración.',
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
}
