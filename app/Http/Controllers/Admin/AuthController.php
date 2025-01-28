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

    public function test()
    {
        return "TEST";
    }
}