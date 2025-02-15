<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    //
    public function index(): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        // Si el usuario pertenece al campus "MERIDA", obtiene todos los registros
        if ($user->campus === 'MERIDA') {
            $data = User::where('user_type', 'BEC_ACTIVE')->get();
        } else {
            // Si no, solo obtiene los registros de su campus
            $data = User::where('campus', $user->campus,)->where('user_type', 'BEC_ACTIVE')->get();
        }

        return response()->json([
            'res' => true,
            'users' => $data
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(User::$createRulesUser);
        $existingUser = User::where('enrollment', $data['enrollment'])->first();

        if (!$existingUser) {
            $data['user_type'] = 'BEC_ACTIVE';
            $data['password'] = Hash::make($data['password']);

            $user = User::create($data);

            return response()->json([
                'res' => true,
                'msg' => 'Usuario creado con éxito',
                'createUser' => $user,
            ], 201);
        }

        return response()->json([
            'res' => false,
            'msg' => 'La matrícula ya está registrada.',
        ], 409);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'user' => $user
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validate(User::updateRulesUser($user->id));

        if ($request->has('active')) {
            $data['active'] = (bool) $request->active;
        }

        $user->fill($data)->save();

        return response()->json([
            'res' => true,
            'msg' => 'Usuario actualizado correctamente',
            'updateUser' => $user
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id) {}
}