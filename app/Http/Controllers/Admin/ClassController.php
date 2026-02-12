<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ClassModel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use App\Exports\AttendanceExport;
use App\Exports\AttendanceMatrixExport;
use App\Exports\TestExport;
use App\Http\Resources\ClassResource;
use App\Models\Generation;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;

class ClassController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {

        $data = $request->validate(ClassModel::indexRules());
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        $classes = ClassModel::where('campus', $data['campus'])
            ->where('generation_id', $data['generation_id'])
            ->orderBy('created_at', 'desc')
            ->get();

        return ClassResource::collection($classes);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->validate(ClassModel::createRules());

        $data['start_time'] = Carbon::createFromFormat('H:i:s', $data['start_time'])->toTimeString();
        $data['end_time']   = Carbon::createFromFormat('H:i:s', $data['end_time'])->toTimeString();
        $class = ClassModel::create($data);

        $userIds = User::where('campus', $data['campus'])
            ->where('generation_id', $data['generation_id'])
            ->where('user_type', 'BEC_ACTIVE')
            ->where('active', true)
            ->pluck('id');

        $attendanceData = $userIds->map(fn($userId) => [
            'class_id'   => $class->id,
            'user_id'    => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->toArray();

        Attendance::insert($attendanceData);

        return ClassResource::make($class);
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
    public function generalReport(Request $request)
    {
        $validatedData = $request->validate([
            'campus' => 'required|string',
            'generation_id' => 'required|integer',
            'year' => 'required|integer',
            'semester' => 'required|integer|in:1,2',
        ]);

        $year = $validatedData['year'];
        $semester = $validatedData['semester'];
        $generationId = $validatedData['generation_id']; // Usar la variable

        // Obtener el nombre de la generación usando el ID
        $generation = Generation::find($generationId);

        // Verificar si la generación existe antes de continuar
        if (!$generation) {
            return response()->json(['message' => 'Generación no encontrada'], 404);
        }

        // 2. Almacenar el nombre que queremos mostrar en el reporte
        $generationName = $generation->generation_name;

        // Rango de fechas por semestre
        if ($semester == 1) {
            $startDate = Carbon::create($year, 1, 1)->startOfDay();
            $endDate   = Carbon::create($year, 6, 30)->endOfDay();
        } else {
            $startDate = Carbon::create($year, 8, 1)->startOfDay();
            $endDate   = Carbon::create($year, 12, 31)->endOfDay();
        }

        // Clases del período (Consulta sin cambios)
        $classes = ClassModel::where('campus', $request->campus)
            ->where('generation_id', $generationId) // Usar $generationId
            ->whereBetween('date', [$startDate, $endDate])
            ->get(['id', 'name', 'date', 'start_time', 'end_time']);

        if ($classes->isEmpty()) {
            return response()->json(['message' => 'No hay clases para este periodo'], 404);
        }

        $classIds = $classes->pluck('id');

        // Obtener asistencias (Consulta sin cambios)
        $attendances = Attendance::with(['user', 'class'])
            ->whereIn('class_id', $classIds)
            ->orderBy('class_id')
            ->orderBy('user_id')
            ->get();

        if ($attendances->isEmpty()) {
            return response()->json(['message' => 'No hay asistencias registradas'], 404);
        }

        // Mapear datos (sin cambios)
        $reportData = $attendances->map(function ($att) {
            return [
                'user' => $att->user->first_name . ' ' . $att->user->last_name,
                'class' => $att->class->name,
                'date' => $att->class->date->format('Y-m-d'),
                'status' => $att->status,
                'check_in' => $att->check_in,
                'check_out' => $att->check_out,
                'observations' => $att->observations,
            ];
        });

        $reportDataGrouped = $reportData->groupBy('class');

        $pdf = PDF::loadView('reports.attendance_general', [
            'reportDataGrouped' => $reportDataGrouped,
            'reportData' => $reportData,
            'campus' => $request->campus,
            'generation' => $generationName,
            'semester' => $semester,
            'year' => $year,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream("Reporte_General_{$year}_S{$semester}.pdf");
    }



    public function generalReportExcel(Request $request)
    {
        $validatedData = $request->validate([
            'campus' => 'required|string',
            'generation_id' => 'required|integer',
            'year' => 'required|integer',
            'semester' => 'required|integer|in:1,2',
        ]);

        if ($validatedData['semester'] == 1) {
            $startDate = Carbon::create($validatedData['year'], 1, 1)->startOfDay();
            $endDate   = Carbon::create($validatedData['year'], 6, 30)->endOfDay();
        } else {
            $startDate = Carbon::create($validatedData['year'], 8, 1)->startOfDay();
            $endDate   = Carbon::create($validatedData['year'], 12, 31)->endOfDay();
        }

        $params = [
            'campus' => $request->campus,
            'generation_id' => $request->generation_id,
            'startDate' => $startDate,
            'endDate' => $endDate
        ];

        return Excel::download(new AttendanceMatrixExport($params), "Reporte_Asistencia_{$request->campus}.xlsx");
    }
}
