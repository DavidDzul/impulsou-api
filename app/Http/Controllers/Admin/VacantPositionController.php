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

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updateVacantJr" => $vacant
        ], 200);
    }
}
