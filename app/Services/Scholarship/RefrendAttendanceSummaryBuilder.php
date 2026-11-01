<?php

namespace App\Services\Scholarship;

use Illuminate\Support\Facades\DB;

/**
 * Single source for the attendance summary frozen into a refrend snapshot.
 * Bound parameters only: the test suite runs on SQLite, production on MySQL.
 */
class RefrendAttendanceSummaryBuilder
{
    /**
     * month_absent = ABSENT inside the window that are still unconsumed, or
     * consumed by a discount of $refrendId (so the number is the same before
     * the refrend exists and after its penalties were applied).
     *
     * @return array{present: int, late: int, absent: int, late_unconsumed: int, total: int, month_absent: int}
     */
    public function build(int $userId, AttendanceWindow $window, ?int $refrendId = null): array
    {
        $rows = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->where('attendances.user_id', $userId)
            ->where('classes.date', '>=', $window->start)
            ->where('classes.date', '<=', $window->end)
            ->selectRaw('attendances.status, COUNT(*) as cnt')
            ->groupBy('attendances.status')
            ->get();

        $summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'late_unjustified' => 0];

        foreach ($rows as $row) {
            $cnt = (int) $row->cnt;
            match ($row->status) {
                'PRESENT'           => $summary['present'] += $cnt,
                'LATE'              => [$summary['late'] += $cnt, $summary['late_unjustified'] += $cnt],
                'JUSTIFIED_LATE'    => $summary['late'] += $cnt,
                'ABSENT'            => $summary['absent'] += $cnt,
                'JUSTIFIED_ABSENCE' => $summary['absent'] += $cnt,
                default             => null,
            };
        }

        $lateConsumed = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->join('scholarship_late_consumptions', 'scholarship_late_consumptions.attendance_id', '=', 'attendances.id')
            ->where('attendances.user_id', $userId)
            ->where('attendances.status', 'LATE')
            ->where('classes.date', '>=', $window->start)
            ->where('classes.date', '<=', $window->end)
            ->count();

        $monthAbsent = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->where('attendances.user_id', $userId)
            ->where('attendances.status', 'ABSENT')
            ->where('classes.date', '>=', $window->start)
            ->where('classes.date', '<=', $window->end)
            ->where(function ($q) use ($refrendId) {
                $q->whereNotExists(function ($sub) {
                    $sub->from('scholarship_late_consumptions')
                        ->whereColumn('scholarship_late_consumptions.attendance_id', 'attendances.id');
                });

                if ($refrendId !== null) {
                    $q->orWhereExists(function ($sub) use ($refrendId) {
                        $sub->from('scholarship_late_consumptions')
                            ->join(
                                'scholarship_refrend_discounts',
                                'scholarship_refrend_discounts.id',
                                '=',
                                'scholarship_late_consumptions.scholarship_refrend_discount_id'
                            )
                            ->whereColumn('scholarship_late_consumptions.attendance_id', 'attendances.id')
                            ->where('scholarship_refrend_discounts.scholarship_refrend_id', $refrendId);
                    });
                }
            })
            ->count();

        return [
            'present'         => $summary['present'],
            'late'            => $summary['late'],
            'absent'          => $summary['absent'],
            'late_unconsumed' => max(0, $summary['late_unjustified'] - $lateConsumed),
            'total'           => $summary['present'] + $summary['late'] + $summary['absent'],
            'month_absent'    => (int) $monthAbsent,
        ];
    }
}
