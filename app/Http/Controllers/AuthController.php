<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserAccess;
use App\Http\Requests\UserEnrollmentRequest;
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
        $user = User::where('email', $request->email)->with('roles')->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'msg' => 'Las credenciales son incorrectas.',
            ]);
        }

        $token = $user->createToken($request->email)->plainTextToken;

        // Validar si el usuario tiene un rol asignado
        $role = $user->roles->first();

        if (!$role) {
            return response()->json([
                "res" => false,
                "msg" => "El usuario no tiene un rol asignado.",
            ], 403);
        }

        $numVisualizations = $role->num_visualizations ?? null;
        $numVacancies = $role->num_vacancies ?? null;

        return response()->json([
            "res" => true,
            "token" => $token,
            "usuario" => [
                "id" => $user->id,
                "first_name" => $user->first_name,
                "last_name" => $user->last_name,
                "email" => $user->email,
                "user_type" => $user->user_type,
                "phone" => $user->phone,
                "role" => [
                    "name" => $role->name,
                    "num_visualizations" => $numVisualizations,
                    "num_vacancies" => $numVacancies,
                ],

            ]
        ], 200);
    }

    public function loginEnrollment(UserEnrollmentRequest $request)
    {
        $user = User::where('enrollment', $request->enrollment)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'msg' => 'Las credenciales son incorrectas.',
            ]);
        }

        $token = $user->createToken($request->enrollment)->plainTextToken;
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