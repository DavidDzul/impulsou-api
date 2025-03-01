<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VacantPosition;

class VacantController extends Controller
{
    public function index()
    {
        $result = VacantPosition::with(['business' => function ($query) {
            $query->select('user_id', 'bs_name', 'bs_locality', 'bs_country', 'bs_state');
        }])
            ->where('status', true)
            ->orderBy('created_at', 'desc')
            ->select('id', 'vacant_name', 'mode', 'category', 'activities', 'created_at', 'status', 'user_id')
            ->get();

        return response()->json([
            'vacancies' => $result
        ]);
    }

    public function show($id)
    {
        $vacant = VacantPosition::with('business')->with('image')->find($id); // Busca directamente en la base de datos

        if (!$vacant) {
            return response()->json([
                'res' => false,
                'message' => 'Vacant not found',
            ], 404);
        }

        if (!$vacant->status) {
            return response()->json([
                'res' => false,
                'message' => 'Vacant is not available',
            ], 403);
        }

        return response()->json([
            'res' => true,
            'vacant' => $vacant,
        ], 200);
    }

    public function storeVacant(Request $request)
    {
        $user = auth()->user();
        $role = $user->roles->first();

        $request->merge([
            'user_id' => $user->id,
            'campus' => $user->campus
        ]);


        $data = $request->validate(VacantPosition::createVacantRules());
        $data['status'] = true;

        if (!$role) {
            return response()->json([
                'res' => false,
                'msg' => 'El usuario no tiene un rol asignado.'
            ], 403);
        }

        if (!$role->unlimited) {
            $count = VacantPosition::where('user_id', $user->id)->where('status', true)->count();

            if ($role->num_vacancies !== null && $count >= $role->num_vacancies) {
                return response()->json([
                    'res' => false,
                    'msg' => 'Has alcanzado el límite máximo de vacantes permitido por tu plan.'
                ], 403);
            }
        }

        $vacant = VacantPosition::create($data);

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            'createVacant' => $vacant
        ], 200);
    }

    public function updateVacant(Request $request, $id)
    {

        $vacant = VacantPosition::findOrFail($id);
        $data = $request->validate(VacantPosition::updateVacantRules());

        $vacant->update($data);

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updateVacant" => $vacant
        ], 200);
    }

    public function storePractice(Request $request)
    {

        $user = auth()->user();
        $role = $user->roles->first();

        $request->merge([
            'user_id' => $user->id,
            'campus' => $user->campus
        ]);

        $data = $request->validate(VacantPosition::createPracticeRules());
        $data['status'] = true;

        if (!$role) {
            return response()->json([
                'res' => false,
                'msg' => 'El usuario no tiene un rol asignado.'
            ], 403);
        }

        if (!$role->unlimited) {
            $count = VacantPosition::where('user_id', $user->id)->where('status', true)->count();

            if ($role->num_vacancies !== null && $count >= $role->num_vacancies) {
                return response()->json([
                    'res' => false,
                    'msg' => 'Has alcanzado el límite máximo de vacantes permitido por tu plan.'
                ], 403);
            }
        }

        $vacant = VacantPosition::create($data);

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            "createPractice" => $vacant
        ], 200);
    }

    public function updatePractice(Request $request, $id)
    {

        $practice = VacantPosition::findOrFail($id);
        $data = $request->validate(VacantPosition::updatePracticeRules());

        $practice->update($data);

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updatePractice" => $practice
        ], 200);
    }

    public function updateVacantStatus(Request $request, $id)
    {
        $vacant = VacantPosition::find($id);
        $data = $request->validate(VacantPosition::updateStatusRules());
        $data['status'] = false;

        if (!$vacant) {
            return response()->json([
                'res' => false,
                'msg' => 'La vacante no ha sido encontrada.',
            ], 404);
        }

        $vacant->update($data);

        return response()->json([
            'res' => true,
            'msg' => 'Estado actualizado con éxito.',
            'vacant' => $vacant,
        ], 200);
    }
}