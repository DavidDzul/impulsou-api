<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CandidateData;
use Illuminate\Http\Request;

class CandidateDataController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $candidates = CandidateData::all();
        return response()->json([
            'res' => true,
            'candidates' => $candidates
        ], 200);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->validate(CandidateData::createRules());
        $area = CandidateData::create($data);

        return response()->json([
            'res' => true,
            'msg' => 'Área creada con éxito',
            'createData' => $area,
        ], 201);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
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
        $candidate = CandidateData::findOrFail($id);
        $data = $request->validate(CandidateData::updateRules());

        $candidate->update($data);

        return response()->json([
            "res" => true,
            'message' => 'Información actualizada correctamente',
            'updateData' => $candidate
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $area = CandidateData::findOrFail($id);
        $area->delete();

        return response()->json(['message' => 'Información eliminada correctamente', 200]);
    }
}