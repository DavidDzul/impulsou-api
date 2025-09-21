<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClassModel;
use Illuminate\Http\Request;

class ClassController extends Controller
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

        if ($user->campus === 'MERIDA') {
            $data = ClassModel::all();
        } else {
            $data = ClassModel::where('campus', $user->campus)->get();
        }

        return response()->json([
            'res' => true,
            'data' => $data
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
        $user = auth()->user();
        $data = $request->validate(ClassModel::createRules());
        $class = ClassModel::create($data);
        $data['campus'] = $user->campus;

        return response()->json([
            'res' => true,
            'msg' => 'Generación creada con éxito',
            'data' => $class,
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
        $model = ClassModel::findOrFail($id);
        $data = $request->validate(ClassModel::updateRules());

        $model->update($data);

        return response()->json([
            "res" => true,
            'message' => 'Clase actualizada correctamente',
            'data' => $model,
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
        $model = ClassModel::findOrFail($id);
        $model->delete();

        return response()->json(['message' => 'Clase eliminada correctamente', 200]);
    }
}