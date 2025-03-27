<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VacantPosition;

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

        $data = VacantPosition::select('id', 'user_id', 'vacant_name', 'status', 'category', 'created_at')
            ->with(['business' => function ($query) {
                $query->select('id', 'user_id', 'bs_name');
            }])
            ->when($user->campus !== 'MERIDA', function ($query) use ($user) {
                return $query->where('campus', $user->campus);
            })
            ->get();

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
