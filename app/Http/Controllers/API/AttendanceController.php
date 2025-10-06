<?php

namespace App\Http\Controllers\API;

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

    public function getAttendanceStatus(Request $request)
    {
        $userId = $request->user()->id;
        $today  = now()->toDateString();

        $attendance = Attendance::where('user_id', $userId)
            ->whereHas('class', function ($q) use ($today) {
                $q->whereDate('date', $today); // filtra por fecha real de la clase
            })
            ->with('class')
            ->latest()
            ->first();

        if (!$attendance) {
            return response()->json([
                'status'  => 'NOT_ASSIGNED',
                'message' => 'No tienes asignación de asistencia para hoy.'
            ]);
        }

        $hasCheckedIn  = !is_null($attendance->check_in) && in_array($attendance->status, ['PRESENT', 'LATE', 'JUSTIFIED']);
        $hasCheckedOut = !is_null($attendance->check_out);

        $canCheckIn  = !$hasCheckedIn;
        $canCheckOut = $hasCheckedIn && !$hasCheckedOut;

        $isCompleted = $hasCheckedIn && $hasCheckedOut && $attendance->class_status === 'COMPLETED';

        return response()->json([
            'status'        => $attendance->status,
            'class_status'  => $attendance->class_status,
            'check_in'      => $attendance->check_in,
            'check_out'     => $attendance->check_out,
            'can_checkin'   => $canCheckIn,
            'can_checkout'  => $canCheckOut,
            'is_completed'  => $isCompleted,
            'class'         => $attendance->class,
        ]);
    }

    public function checkout(Request $request)
    {
        $userId = $request->user()->id;
        $today  = now()->toDateString();

        // Buscar la asistencia vinculada a la clase de hoy
        $attendance = Attendance::where('user_id', $userId)
            ->whereHas('class', function ($q) use ($today) {
                $q->whereDate('date', $today);
            })
            ->with('class')
            ->latest()
            ->first();

        if (!$attendance) {
            return response()->json([
                'status'  => 'NOT_ASSIGNED',
                'message' => 'No tienes asignación de asistencia para hoy.'
            ], 404);
        }

        // Validar que ya haya hecho check-in
        if (is_null($attendance->check_in)) {
            return response()->json([
                'status'  => 'ERROR',
                'message' => 'No puedes hacer check-out sin haber hecho check-in primero.'
            ], 400);
        }

        // Validar que no haya hecho ya el check-out
        if (!is_null($attendance->check_out)) {
            return response()->json([
                'status'  => 'ALREADY_COMPLETED',
                'message' => 'Ya has marcado tu salida.'
            ], 400);
        }

        // Registrar check-out
        $attendance->check_out = now()->format('H:i:s');
        $attendance->class_status = 'COMPLETED';
        $attendance->save();

        // Flags
        $hasCheckedIn  = !is_null($attendance->check_in) && in_array($attendance->status, ['PRESENT', 'LATE', 'JUSTIFIED']);
        $hasCheckedOut = !is_null($attendance->check_out);

        $canCheckIn  = !$hasCheckedIn;
        $canCheckOut = $hasCheckedIn && !$hasCheckedOut;

        $isCompleted = $hasCheckedIn && $hasCheckedOut && $attendance->class_status === 'COMPLETED';

        // Devolver respuesta inmediata para que el front actualice sin esperar onMounted
        return response()->json([
            'status'        => $attendance->status,
            'class_status'  => $attendance->class_status,
            'check_in'      => $attendance->check_in,
            'check_out'     => $attendance->check_out,
            'can_checkin'   => $canCheckIn,
            'can_checkout'  => $canCheckOut,
            'is_completed'  => $isCompleted,
            'class'         => $attendance->class,
        ]);
    }
}
