<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\VacantPosition;

class VacantPositionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // $user = auth()->user();
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        // Visibility follows the owner business (see VacantPosition::scopeVisibleTo).
        $query = VacantPosition::select('id', 'user_id', 'vacant_name', 'status', 'category', 'created_at')
            ->with(['business' => function ($query) {
                $query->select('id', 'user_id', 'bs_name');
            }])
            ->visibleTo($user);

        $data = $query->get();

        return response()->json([
            'res' => true,
            'positions' => $data
        ]);
    }



    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        $vacant = $this->visibleVacancy($request, $id);

        return response()->json([
            'vacant' => $vacant
        ]);
    }

    public function storeVacant(Request $request, $id)
    {
        $user = $this->visibleOwner($request, $id);
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

        $vacant = $this->visibleVacancy($request, $id);
        $data = $request->validate(VacantPosition::updateVacantRules());
        unset($data['user_id']); // the owner of a vacancy is immutable

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

        $user = $this->visibleOwner($request, $id);
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

        $practice = $this->visibleVacancy($request, $id);
        $data = $request->validate(VacantPosition::updatePracticeRules());
        unset($data['user_id']);

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
        $user = $this->visibleOwner($request, $id);
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

        $vacant = $this->visibleVacancy($request, $id);
        $data = $request->validate(VacantPosition::updateJrRules());
        unset($data['user_id']);

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
        $vacant = $this->visibleVacancy($request, $id);
        $data = $request->validate(VacantPosition::updateStatusRules());
        $data['status'] = false;

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
        $vacant = $this->visibleVacancy($request, $id);

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

    /**
     * 404 (never findOrFail: the Handler maps ModelNotFoundException to 400) for a missing vacancy
     * or one whose owner business is not visible to the actor.
     */
    private function visibleVacancy(Request $request, $id): VacantPosition
    {
        $vacant = VacantPosition::visibleTo($request->user())->find($id);
        abort_if(!$vacant, 404);

        return $vacant;
    }

    /** The owner of a new vacancy must be a BUSINESS user visible to the actor, even for ROOT. */
    private function visibleOwner(Request $request, $id): User
    {
        $owner = User::businessVisibleTo($request->user())->find($id);
        abort_if(!$owner, 404);

        return $owner;
    }
}
