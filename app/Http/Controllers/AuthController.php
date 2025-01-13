<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserAccess;
use App\Http\Requests\RegistroRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use App\Http\Requests\UpdateUserRequest;

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

    public function updateUser(UpdateUserRequest $request)
    {
        $user = User::findOrFail($request->id);

        // Actualizar los datos del usuario
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->email = $request->email;

        // Solo actualizar la contraseña si se envía
        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        $user->phone = $request->phone;

        $user->save();

        return response()->json([
            'res' => true,
            "msg" => "Actualización realizada con éxito",
            "user" => $user
        ], 200);
    }
}