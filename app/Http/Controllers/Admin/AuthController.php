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
    /** bcrypt hash of a throwaway string; only used to equalize login timing. */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function login(UserAccess $request)
    {
        $user = User::where('email', $request->email)
            ->where('active', true)
            ->first();

        if (!$user) {
            // Constant-ish timing: spend a hash check so absent/inactive accounts are not distinguishable.
            Hash::check((string) $request->password, self::DUMMY_HASH);
        }

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
            'permissions' => $permissions->pluck('name'),
            'can_view_all_campuses' => $user->hasGlobalBusinessVisibility(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        // Always the authenticated account: a body `id` must never select another user.
        $user = $request->user();
        $data = $request->validate(User::updateRulesProfile($user->id));
        unset($data['id']);

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
