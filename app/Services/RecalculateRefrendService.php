<?php

namespace App\Services;

use App\Enums\DiscountType;
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

            $hasProfileDiscount = isset($snapshot['snapshot_discount_percentage'])
                && $snapshot['snapshot_discount_percentage'] !== null
                && (float) $snapshot['snapshot_discount_percentage'] > 0;

            $newWorkflowStatus   = $hasProfileDiscount ? 'CON_INCIDENCIA' : 'DRAFT';
            $incidentDescription = null;

            if ($hasProfileDiscount) {
                $pct        = $snapshot['snapshot_discount_percentage'];
                $reason     = $snapshot['snapshot_discount_reason'] ?? null;
                $validUntil = $profile->discount_valid_until
                    ? Carbon::parse($profile->discount_valid_until)->format('d/m/Y')
                    : null;

                $parts = ["Descuento académico del {$pct}%"];
                if ($validUntil) {
                    $parts[] = "vigente hasta {$validUntil}";
                }
                if ($reason) {
                    $parts[] = "Motivo: {$reason}";
                }
                $incidentDescription = implode(', ', $parts) . '.';
            }

            $refrend->update([
                'snapshot_name'               => $snapshot['snapshot_name'],
                'snapshot_generation'         => $snapshot['snapshot_generation'],
                'snapshot_generation_id'      => $snapshot['snapshot_generation_id'] ?? null,
                'snapshot_campus'             => $snapshot['snapshot_campus'],
                'snapshot_scholarship_type'   => $snapshot['snapshot_scholarship_type'],
                'snapshot_gross_amount'        => $snapshot['snapshot_gross_amount'],
                'snapshot_monto_apoyo'         => $snapshot['snapshot_monto_apoyo'],
                'snapshot_temporary_increase_amount' => $snapshot['snapshot_temporary_increase_amount'],
                'snapshot_temporary_increase_reason' => $snapshot['snapshot_temporary_increase_reason'],
                'base_amount'                  => $snapshot['base_amount'],
                'snapshot_discount_percentage' => $snapshot['snapshot_discount_percentage'],
                'snapshot_discount_reason'     => $snapshot['snapshot_discount_reason'],
                'average_grade_snapshot'       => $lastGrade,
                'workflow_status'             => $newWorkflowStatus,
                // Reset any manual amount override so recalculation starts clean
                'discount_percentage'         => 0,
                'discount_amount'             => 0,
                'final_amount'                => $snapshot['base_amount'],
            ]);

            // Idempotency: this incident is machine-generated and re-derived below, so
            // it must be cleared first — same unconditional-delete-then-reapply contract
            // as the discount rows in steps 2 and 3. Without this, every recalculation of
            // a becario with an active profile discount appends a duplicate (reachable via
            // ClearRefrendResolutionAction, which sets DRAFT then calls us). The
            // created_by_id IS NULL clause guarantees we only ever delete rows WE created
            // and never a human-filed incident that happens to share the type.
            $refrend->incidents()
                ->where('incident_type', 'DESCUENTO_PERFIL')
                ->where('is_resolved', false)
                ->whereNull('created_by_id')
                ->delete();

            if ($hasProfileDiscount && $incidentDescription !== null) {
                $refrend->incidents()->create([
                    'incident_category' => 'ACADEMICO',
                    'incident_type'     => 'DESCUENTO_PERFIL',
                    'description'       => $incidentDescription,
                    'priority'          => 'LOW',
                    'created_by_id'     => null,
                ]);
            }

            // 2. Remove RETARDOS discounts (cascade removes late consumptions automatically).
            ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
                ->where('discount_type', DiscountType::RETARDOS->value)
                ->delete();

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

        $monthPadded      = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $periodMonthStart = "{$year}-{$monthPadded}-01";
        // Bound parameter instead of DB::raw('CURDATE()') — CURDATE() is MySQL-only
        // syntax and errors ("no such function: CURDATE") against the SQLite
        // in-memory connection used by the test suite (phpunit.xml). Same semantics
        // in production (MySQL): "today", computed once so all three queries below
        // agree even across a midnight rollover during a slow request.
        $today = now()->toDateString();

        $rows = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->where('attendances.user_id', $userId)
            ->where('classes.date', '>=', $start)
            ->where('classes.date', '<=', $today)
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
            ->where('classes.date', '>=', $start)
            ->where('classes.date', '<=', $today)
            ->count();

        $monthAbsent = DB::table('attendances')
            ->join('classes', 'attendances.class_id', '=', 'classes.id')
            ->where('attendances.user_id', $userId)
            ->where('attendances.status', 'ABSENT')
            ->where('classes.date', '>=', $periodMonthStart)
            ->where('classes.date', '<=', $today)
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
