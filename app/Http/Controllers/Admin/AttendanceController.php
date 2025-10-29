<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Attendance;
use App\Models\AttendanceToken;
use Carbon\Carbon;

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

        $attendance = attendance::findOrFail($id);
        $data = $request->validate(Attendance::updateRules());
        $data['class_status'] = 'COMPLETED';

        $attendance->update($data);
        $attendance->load('user');

        return response()->json([
            'res' => true,
            'data' => $attendance,
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
        $data = Attendance::findOrFail($id);
        $data->delete();

        return response()->json(['message' => 'Eliminado correctamente', 200]);
    }

    public function checkIn(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $token = AttendanceToken::where('token', $request->token)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        // Primero verificar si existe
        if (!$token) {
            return response()->json([
                'message' => 'Token inválido o expirado.'
            ], 401);
        }

        // Luego verificar si ya fue usado
        if ($token->used) {
            return response()->json([
                'message' => 'El token ya fue utilizado.'
            ], 400);
        }

        $userId = $token->user_id;
        $today  = now()->toDateString();

        $attendance = Attendance::where('user_id', $userId)
            ->whereHas('class', function ($q) use ($today) {
                $q->whereDate('date', $today);
            })
            ->with('class', 'user')
            ->latest()
            ->first();

        if (!$attendance) {
            return response()->json([
                'message' => 'No tienes una clase asignada para hoy.'
            ], 404);
        }

        if (!is_null($attendance->check_in)) {
            return response()->json([
                'message' => 'Ya has registrado tu entrada.'
            ], 400);
        }

        // Registrar check-in
        $attendance->check_in = now()->format('H:i:s');

        $timeNow = now();
        $classStart = Carbon::parse($attendance->class->start_time);
        $attendance->check_in = $timeNow->format('H:i:s');
        $attendance->status = $timeNow->gt($classStart) ? 'LATE' : 'PRESENT';

        $attendance->class_status = 'IN_PROCESS';
        $attendance->save();

        // Marcar token como usado
        $token->used = true;
        $token->save();

        return response()->json([
            'message' => 'Check-in registrado con éxito.',
            'attendance' => [
                'status'        => $attendance->status,
                'check_in'      => $attendance->check_in,
                'class'         => [
                    'id'          => $attendance->class->id,
                    'name'        => $attendance->class->name,
                ],
                'user' => [
                    'id' => $attendance->user->id,
                    'first_name' => $attendance->user->first_name,
                    'last_name' => $attendance->user->last_name,
                ],
            ]
        ]);
    }
}
