<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VacantPosition;

class VacantController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $userType = $user->user_type;

        // Construir la consulta base con relaciones y filtros iniciales
        $query = VacantPosition::with(['business' => function ($query) {
            $query->select('user_id', 'bs_name', 'bs_locality', 'bs_country', 'bs_state');
        }])
            ->where('status', true)
            ->select('id', 'vacant_name', 'mode', 'category', 'activities', 'created_at', 'status', 'user_id');

        // Filtrar si el usuario es BEC_INACTIVE
        if ($userType === 'BEC_INACTIVE') {
            $query->where('category', 'JOB_POSITION');
        }

        $result = $query->orderBy('created_at', 'desc')->get();

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
        $user = $request->user();
        $role = $user->roles()->with('configuration')->first();

        $request->merge([
            'user_id' => $user->id,
            'campus' => $user->campus
        ]);

        $data = $request->validate(VacantPosition::createVacantRules());
        $data['status'] = true;

        if (!$role && !$role->configuration) {
            return response()->json([
                'res' => false,
                'msg' => 'Error con el rol asignado. Contacta a soporte para solucionar el problema.'
            ], 403);
        }

        $roleConfig = $role->configuration;

        if (!$roleConfig->unlimited) {
            $count = VacantPosition::where('user_id', $user->id)->where('status', true)->count();

            if ($roleConfig->num_vacancies !== null && $count >= $roleConfig->num_vacancies) {
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

        $user = $request->user();
        $role = $user->roles()->with('configuration')->first();

        $request->merge([
            'user_id' => $user->id,
            'campus' => $user->campus
        ]);

        $data = $request->validate(VacantPosition::createPracticeRules());
        $data['status'] = true;

        if (!$role && !$role->configuration) {
            return response()->json([
                'res' => false,
                'msg' => 'Error con el rol asignado. Contacta a soporte para solucionar el problema.'
            ], 403);
        }

        $roleConfig = $role->configuration;

        if (!$roleConfig->unlimited) {
            $count = VacantPosition::where('user_id', $user->id)->where('status', true)->count();

            if ($roleConfig->num_vacancies !== null && $count >= $roleConfig->num_vacancies) {
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

    public function storeVacantJr(Request $request)
    {
        $user = $request->user();
        $role = $user->roles()->with('configuration')->first();

        $request->merge([
            'user_id' => $user->id,
            'campus' => $user->campus,
            'category' => 'JR_POSITION'
        ]);

        $data = $request->validate(VacantPosition::createJrRules());
        $data['status'] = true;

        if (!$role && !$role->configuration) {
            return response()->json([
                'res' => false,
                'msg' => 'Error con el rol asignado. Contacta a soporte para solucionar el problema.'
            ], 403);
        }

        $roleConfig = $role->configuration;

        if (!$roleConfig->unlimited) {
            $count = VacantPosition::where('user_id', $user->id)->where('status', true)->count();

            if ($roleConfig->num_vacancies !== null && $count >= $roleConfig->num_vacancies) {
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
            "createVacantJr" => $vacant
        ], 200);
    }

    public function updateVacantJr(Request $request, $id)
    {

        $vacant = VacantPosition::findOrFail($id);
        $data = $request->validate(VacantPosition::updateJrRules());

        $vacant->update($data);

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updateVacantJr" => $vacant
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
