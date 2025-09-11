<?php

namespace App\Http\Controllers\API;

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
        $today = now()->toDateString();

        $attendance = Attendance::where('user_id', $userId)
            ->whereDate('created_at', $today)
            ->with('class')
            ->latest()
            ->first();

        if (!$attendance) {
            return null;
        }

        $hasCheckedIn = !is_null($attendance->check_in) && in_array($attendance->status, ['PRESENT', 'LATE']);
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
}