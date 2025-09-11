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
        $user = User::where('email', $request->email)
            ->where('active', true)
            ->with(['roles.configuration', 'agreement'])
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'msg' => 'Las credenciales son incorrectas.',
            ]);
        }

        // Verificar si el usuario tiene un convenio activo
        $agreement = $user->agreement;
        if (!$agreement || now()->gt($agreement->end_date)) {
            return response()->json([
                "res" => false,
                "msg" => "El convenio de la empresa ha expirado. Contacta a soporte para renovarlo.",
            ], 403);
        }

        // Obtener el primer rol asignado al usuario
        $role = $user->roles->first();
        if (!$role) {
            return response()->json([
                "res" => false,
                "msg" => "El usuario no tiene un rol asignado.",
            ], 403);
        }

        // Obtener configuración del rol
        $config = $role->configuration;
        if (!$config) {
            return response()->json([
                "res" => false,
                "msg" => "El rol asignado no tiene configuración válida. Contacta a soporte.",
            ], 403);
        }

        $token = $user->createToken($request->email)->plainTextToken;

        return response()->json([
            "res" => true,
            "token" => $token,
            "usuario" => [
                "id" => $user->id,
                "first_name" => $user->first_name,
                "last_name" => $user->last_name,
                "email" => $user->email,
                "phone" => $user->phone,
                "user_type" => $user->user_type,
                "workstation" => $user->workstation,
                "role" => [
                    "name" => $role->name,
                    "num_visualizations" => $config->num_visualizations,
                    "num_vacancies" => $config->num_vacancies,
                    "unlimited" => $config->unlimited,
                ],
            ]
        ], 200);
    }

    public function loginEnrollment(UserEnrollmentRequest $request)
    {
        $user = User::where('enrollment', $request->enrollment)->where('active', true)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'msg' => 'Las credenciales son incorrectas.',
            ]);
        }

        $token = $user->createToken($request->enrollment)->plainTextToken;
        return response()->json([
            "res" => true,
            "token" => $token,
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
