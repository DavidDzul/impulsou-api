<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Models\Attendance;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RecalculateRefrendService
{
    public function __construct(
        private ScholarshipCalculationService $calculationService,
        private AttendancePenaltyService $penaltyService,
        private ScholarshipLoggingService $loggingService
    ) {}

    /**
     * Full recalculation pipeline. Only allowed in DRAFT state.
     * Refreshes all snapshots, clears and re-applies auto discounts,
     * then recalculates final amount. Preserves the same refrend_id.
     *
     * @throws \DomainException if the refrend is not in DRAFT.
     */
    public function fullRecalculate(ScholarshipRefrend $refrend): ScholarshipRefrend
    {
        if ($refrend->workflow_status !== 'DRAFT') {
            throw new \DomainException('Solo se pueden recalcular refrendos en estado DRAFT.');
        }

        $profile       = ScholarshipProfile::where('user_id', $refrend->user_id)->firstOrFail();
        $user          = $profile->user()->with('roles')->first();
        $year          = $refrend->period_year;
        $month         = $refrend->period_month;
        $referenceDate = Carbon::create($year, $month, 1);

        return DB::transaction(function () use ($refrend, $profile, $user, $year, $month, $referenceDate) {
            $old = $this->loggingService->snapshotRefrend($refrend);

            // 1. Refresh non-attendance snapshots
            $snapshot  = $this->calculationService->buildSnapshot($profile);
            $lastGrade = $this->getLastSemesterGrade($refrend->user_id);

            $refrend->update([
                'snapshot_name'               => $snapshot['snapshot_name'],
                'snapshot_generation'         => $snapshot['snapshot_generation'],
                'snapshot_generation_id'      => $snapshot['snapshot_generation_id'] ?? null,
                'snapshot_campus'             => $snapshot['snapshot_campus'],
                'snapshot_scholarship_type'   => $snapshot['snapshot_scholarship_type'],
                'base_amount'                  => $snapshot['base_amount'],
                'snapshot_discount_percentage' => $snapshot['snapshot_discount_percentage'],
                'snapshot_discount_reason'     => $snapshot['snapshot_discount_reason'],
                'average_grade_snapshot'       => $lastGrade,
                // Reset any manual amount override so recalculation starts clean
                'discount_percentage'         => 0,
                'discount_amount'             => 0,
                'final_amount'                => $snapshot['base_amount'],
            ]);

            // 2. Remove RETARDOS discounts and unmark consumed attendances.
            // Reset directly by refrend_id first so orphaned flags (pivot deleted manually)
            // are always cleared regardless of pivot state.
            Attendance::where('user_id', $refrend->user_id)
                ->where('late_penalty_consumed_refrend_id', $refrend->id)
                ->update([
                    'late_penalty_consumed'            => false,
                    'late_penalty_consumed_refrend_id' => null,
                ]);

            ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
                ->where('discount_type', DiscountType::RETARDOS->value)
                ->with('lateConsumptions')
                ->get()
                ->each(function ($discount) {
                    $discount->lateConsumptions()->delete();
                    $discount->delete();
                });

            // 3. Remove academic and absence discounts
            ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
                ->whereIn('discount_type', [
                    DiscountType::PROMEDIO_BAJO->value,
                    DiscountType::FALTA_INJUSTIFICADA->value,
                ])
                ->delete();

            // 4. Re-apply automatic discounts on a fresh instance
            $fresh = $refrend->fresh();
            $this->penaltyService->applyPenaltyIfDue($fresh, $user, 100.0, $referenceDate);
            $this->penaltyService->applyAbsencePenaltyIfDue($fresh, $user, $year, $month);

            // 4.5 Compute attendance snapshot AFTER penalties are re-applied so
            // late_unconsumed reflects the actual post-recalculation state.
            $attendanceSummary = $this->getAttendanceSummaryForPeriod($refrend->user_id, $year, $month);
            $fresh->update(['attendance_summary_snapshot' => $attendanceSummary]);

            // 5. Recalculate final amount from all remaining discounts
            $fresh = $this->calculationService->recalculate($fresh);

            // 6. Log the recalculation
            $this->loggingService->log(
                $fresh,
                'FULL_RECALCULATE',
                $old,
                $this->loggingService->snapshotRefrend($fresh)
            );

            return $fresh;
        });
    }

    private function getLastSemesterGrade(int $userId): ?float
    {
        $row = DB::table('scholarship_semester_grades')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('grade');

        return $row !== null ? (float) $row : null;
    }

    private function getAttendanceSummaryForPeriod(int $userId, int $year, int $month): array
    {
        $start = $month <= 7
            ? Carbon::create($year, 1, 1)->toDateString()
            : Carbon::create($year, 8, 1)->toDateString();

        $rows = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->where('attendances.user_id', $userId)
            ->where('classes.date', '>=', $start)
            ->where('classes.date', '<=', DB::raw('CURDATE()'))
            ->selectRaw('attendances.status, attendances.late_penalty_consumed, COUNT(*) as cnt')
            ->groupBy('attendances.status', 'attendances.late_penalty_consumed')
            ->get();

        $summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'late_consumed' => 0];

        foreach ($rows as $row) {
            $cnt = (int) $row->cnt;
            match ($row->status) {
                'PRESENT'           => $summary['present'] += $cnt,
                'LATE'              => $summary['late'] += $cnt,
                'JUSTIFIED_LATE'    => $summary['late'] += $cnt,
                'ABSENT'            => $summary['absent'] += $cnt,
                'JUSTIFIED_ABSENCE' => $summary['absent'] += $cnt,
                default             => null,
            };
            if ($row->late_penalty_consumed) {
                $summary['late_consumed'] += $cnt;
            }
        }

        return [
            'present'         => $summary['present'],
            'late'            => $summary['late'],
            'absent'          => $summary['absent'],
            'late_unconsumed' => max(0, $summary['late'] - $summary['late_consumed']),
        ];
    }
}
