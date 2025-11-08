<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class AttendanceStatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request)
    {
        $attendance = $this;
        $class = $attendance->class;
        $now   = Carbon::now();

        // Verificar si la clase ya terminó
        $classEnded = false;
        if ($class && $class->end_time) {
            $classEnded = $now->greaterThanOrEqualTo(Carbon::parse($class->end_time));
        }

        // Normalizar check_in / check_out
        $checkIn  = $attendance->check_in !== '00:00:00' ? $attendance->check_in : null;
        $checkOut = $attendance->check_out !== '00:00:00' ? $attendance->check_out : null;

        // Evaluar estado de check-in/out
        $hasCheckedIn  = !is_null($checkIn)
            && in_array($attendance->status, ['PRESENT', 'LATE', 'JUSTIFIED_LATE', 'JUSTIFIED_ABSENCE']);
        $hasCheckedOut = !is_null($checkOut);

        // Nueva lógica
        $canCheckIn  = !$hasCheckedIn && !$classEnded;
        $canCheckOut = $hasCheckedIn && !$hasCheckedOut; // Permite aunque classEnded sea true

        $isCompleted = $hasCheckedIn && $hasCheckedOut && $attendance->class_status === 'COMPLETED';

        return [
            'status'        => $attendance->status,
            'class_status'  => $attendance->class_status,
            'check_in'      => $checkIn,
            'check_out'     => $checkOut,
            'can_checkin'   => $canCheckIn,
            'can_checkout'  => $canCheckOut,
            'is_completed'  => $isCompleted,
            'class_ended'   => $classEnded,
            'class'         => $class,
        ];
    }
}
