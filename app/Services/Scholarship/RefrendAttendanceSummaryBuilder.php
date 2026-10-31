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
     * Legacy adapter (behavior-preserving): window = semester of the refrend
     * period up to today, month_absent counted from the period's first day.
     * Replaced by reference-date windows in the next slice.
     */
    public function buildForPeriod(int $userId, int $year, int $month): array
    {
        $semesterStartMonth = $month <= 7 ? 1 : 8;
        $start = sprintf('%04d-%02d-01', $year, $semesterStartMonth);

        return $this->build(
            $userId,
            new AttendanceWindow($start, now()->toDateString()),
            sprintf('%04d-%02d-01', $year, $month)
        );
    }

    /**
     * @param string|null $monthAbsentFrom Y-m-d lower bound for month_absent;
     *                                     defaults to the window start.
     *
     * @return array{present: int, late: int, absent: int, late_unconsumed: int, total: int, month_absent: int}
     */
    public function build(int $userId, AttendanceWindow $window, ?string $monthAbsentFrom = null): array
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
            ->where('classes.date', '>=', $monthAbsentFrom ?? $window->start)
            ->where('classes.date', '<=', $window->end)
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
