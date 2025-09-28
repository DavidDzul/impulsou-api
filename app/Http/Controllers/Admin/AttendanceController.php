<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Attendance;

class AttendanceController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }



    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // Validamos request
        $data = $request->validate([
            'class_id'   => 'required|exists:classes,id',
            'user_ids'   => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $classId = $data['class_id'];
        $incomingUserIds = $data['user_ids'];

        // Usuarios que ya están en attendance
        $existingAttendances = Attendance::where('class_id', $classId)->get();

        $existingUserIds = $existingAttendances->pluck('user_id')->toArray();

        // Diferencias
        $toInsert = array_diff($incomingUserIds, $existingUserIds);
        $toDelete = array_diff($existingUserIds, $incomingUserIds);

        // Insertar nuevos
        foreach ($toInsert as $userId) {
            Attendance::create([
                'class_id' => $classId,
                'user_id'  => $userId,
            ]);
        }

        // Filtrar los que sí se pueden eliminar (check_in = null)
        $toDeleteFiltered = $existingAttendances
            ->whereIn('user_id', $toDelete)
            ->filter(fn($att) => is_null($att->check_in))
            ->pluck('user_id')
            ->toArray();

        // Eliminar solo los permitidos
        if (!empty($toDeleteFiltered)) {
            Attendance::where('class_id', $classId)
                ->whereIn('user_id', $toDeleteFiltered)
                ->delete();
        }

        // Retornar los attendance actualizados con relación a user
        $attendances = Attendance::with('user')
            ->where('class_id', $classId)
            ->get();

        return response()->json([
            'res'  => true,
            'data' => $attendances,
        ]);
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
}