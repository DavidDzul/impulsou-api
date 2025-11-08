<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\AttendanceStatusResource;
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


    public function getHistory($id, Request $request)
    {
        $data = $request->validate(Attendance::validateHistoy());

        $attendance = Attendance::where('user_id', $id)
            ->whereHas('class', function ($query) use ($data) {
                $query->whereYear('date', $data['year']);
            })
            ->with('class')
            ->get();

        return AttendanceResource::collection($attendance);
    }

    public function getAttendanceStatus(Request $request)
    {
        $userId = $request->user()->id;
        $today  = now()->toDateString();

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
            ]);
        }

        return new AttendanceStatusResource($attendance);
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
        if (is_null($attendance->check_in) || $attendance->check_in === '00:00:00') {
            return response()->json([
                'status'  => 'ERROR',
                'message' => 'No puedes hacer check-out sin haber hecho check-in primero.'
            ], 400);
        }

        // Validar que no haya hecho ya el check-out
        if (!is_null($attendance->check_out) && $attendance->check_out !== '00:00:00') {
            return response()->json([
                'status'  => 'ALREADY_COMPLETED',
                'message' => 'Ya has marcado tu salida.'
            ], 400);
        }

        $attendance->check_out = now()->format('H:i:s');
        $attendance->class_status = 'COMPLETED';
        $attendance->save();

        return new AttendanceStatusResource($attendance);
    }
}