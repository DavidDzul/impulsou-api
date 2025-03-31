<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\VacantPosition;
use App\Models\UserBusinessMap;

class VacantPositionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        $role = $user->roles->first();

        $query = VacantPosition::select('id', 'user_id', 'vacant_name', 'status', 'category', 'created_at')
            ->with(['business' => function ($query) {
                $query->select('id', 'user_id', 'bs_name');
            }]);

        if ($role && $role->name === 'YUCATAN') {
            $userIds = UserBusinessMap::where('user_id', $user->id)
                ->pluck('business_id');

            $query->whereIn('user_id', $userIds);
        } else if ($user->campus !== 'MERIDA') {
            $query->where('campus', $user->campus);
        }

        $data = $query->get();

        return response()->json([
            'res' => true,
            'positions' => $data
        ]);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $vacant = VacantPosition::findOrFail($id);

        return response()->json([
            'vacant' => $vacant
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function storeVacant(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $role = $user->roles()->with('configuration')->first();

        $request->merge([
            'user_id' => $user->id,
            'campus' => $user->campus
        ]);

        $data = $request->validate(VacantPosition::createVacantRules());
        $data['status'] = true;
        // $data['category'] = 'JOB_POSTION';

        if (!$role || !$role->configuration) {
            return response()->json([
                'res' => false,
                'msg' => 'Error con el rol asignado. Contacta a soporte para solucionar el problema.'
            ], 403);
        }

        $roleConfig = $role->configuration;

        if (!$roleConfig->unlimited) {
            $count = VacantPosition::where('user_id', $user->id)->count();

            if ($roleConfig->num_vacancies !== null && $count >= $roleConfig->num_vacancies) {
                return response()->json([
                    'res' => false,
                    'msg' => 'Has alcanzado el límite máximo de vacantes permitido por tu plan.'
                ], 403);
            }
        }

        $vacant = VacantPosition::create($data);
        $vacant = VacantPosition::with('business')->find($vacant->id);

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
        $vacant->load(['business:id,user_id,bs_name']);

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updateVacant" => $vacant
        ], 200);
    }

    public function storePractice(Request $request, $id)
    {

        $user = User::findOrFail($id);
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
            $count = VacantPosition::where('user_id', $user->id)->count();

            if ($roleConfig->num_vacancies !== null && $count >= $roleConfig->num_vacancies) {
                return response()->json([
                    'res' => false,
                    'msg' => 'Has alcanzado el límite máximo de vacantes permitido por tu plan.'
                ], 403);
            }
        }

        $vacant = VacantPosition::create($data);
        $vacant = VacantPosition::with('business')->find($vacant->id);

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
        $practice->load(['business:id,user_id,bs_name']);

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updatePractice" => $practice
        ], 200);
    }

    public function storeVacantJr(Request $request, $id)
    {
        $user = User::findOrFail($id);
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
            $count = VacantPosition::where('user_id', $user->id)->count();

            if ($roleConfig->num_vacancies !== null && $count >= $roleConfig->num_vacancies) {
                return response()->json([
                    'res' => false,
                    'msg' => 'Has alcanzado el límite máximo de vacantes permitido por tu plan.'
                ], 403);
            }
        }

        $vacant = VacantPosition::create($data);
        $vacant = VacantPosition::with('business')->find($vacant->id);

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
        $vacant->load(['business:id,user_id,bs_name']);

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updateVacantJr" => $vacant
        ], 200);
    }

    public function updateStatus(Request $request, $id)
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

        // Cargar relación business con solo los campos id, user_id y bs_name
        $vacant->load(['business:id,user_id,bs_name']);

        return response()->json([
            'res' => true,
            'msg' => 'Estado actualizado con éxito.',
            'vacant' => $vacant,
        ], 200);
    }

    public function resetStatus(Request $request, $id)
    {
        $vacant = VacantPosition::find($id);

        if (!$vacant) {
            return response()->json([
                'res' => false,
                'msg' => 'La vacante no ha sido encontrada.',
            ], 404);
        }

        $vacant->update([
            'status' => true,
            'candidate_type' => null,
            'candidate_other' => null,
        ]);

        $vacant->load(['business:id,user_id,bs_name']);

        return response()->json([
            'res' => true,
            'msg' => 'Estado de la vacante restablecido con éxito.',
            'vacant' => $vacant,
        ], 200);
    }
}
