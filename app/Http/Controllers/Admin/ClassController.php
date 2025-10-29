<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassModel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use App\Exports\AttendanceExport;
use App\Exports\TestExport;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

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

        // Asegura el formato correcto de hora (HH:MM:SS)
        $data['start_time'] = Carbon::createFromFormat('H:i', $data['start_time'])->format('H:i:s');
        $data['end_time']   = Carbon::createFromFormat('H:i', $data['end_time'])->format('H:i:s');

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
        $data = ClassModel::findOrFail($id);

        return response()->json([
            'data' => $data
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

    public function getAttendanceByClass($id)
    {
        $data = Attendance::with('user')
            ->where('class_id', $id)
            ->get();

        return response()->json([
            "res" => true,
            "data" => $data,
        ]);
    }

    public function sheet($id)
    {

        return Excel::download(new TestExport($id), "Asistencias_Clase_{$id}.xlsx");
    }

    public function pdf($id)
    {

        $class = ClassModel::with(['attendances.user'])
            ->findOrFail($id);

        $pdf = PDF::loadView('reports.attendance', [
            'class' => $class,
            'attendances' => $class->attendances,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream("Reporte_{$class->name}.pdf");
    }
}
