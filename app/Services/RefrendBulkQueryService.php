<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RefrendBulkQueryService
{
    /**
     * Build the paginated bulk master table for a given period.
     *
     * Returns an array with:
     *   - rows: array of BulkRefrendRow-compatible objects
     *   - meta: pagination metadata
     */
    public function buildTable(
        int $year,
        int $month,
        ?string $campus,
        ?int $generationId,
        int $page,
        int $perPage
    ): array {
        // ── Base query: fetch refrends for the period ──────────────────────
        $query = DB::table('scholarship_refrends as r')
            ->where('r.period_year', $year)
            ->where('r.period_month', $month);

        if ($campus !== null) {
            $query->where('r.snapshot_campus', $campus);
        }

        if ($generationId !== null) {
            $query->where('r.snapshot_generation_id', $generationId);
        }

        $total   = $query->count();
        $offset  = ($page - 1) * $perPage;

        $refrends = $query
            ->orderBy('r.snapshot_name')
            ->offset($offset)
            ->limit($perPage)
            ->get([
                'r.id',
                'r.user_id',
                'r.period_year',
                'r.period_month',
                'r.refrend_type',
                'r.status',
                'r.workflow_status',
                'r.resolution_type',
                'r.resolution_cause',
                'r.resolution_notes',
                'r.suspension_percentage',
                'r.base_amount',
                'r.snapshot_discount_percentage',
                'r.snapshot_discount_reason',
                'r.discount_percentage',
                'r.discount_amount',
                'r.final_amount',
                'r.amount_pending_from_previous',
                'r.snapshot_name',
                'r.snapshot_generation',
                'r.snapshot_generation_id',
                'r.snapshot_campus',
                'r.snapshot_scholarship_type',
                'r.atencion_observations',
                'r.atencion_labels',
                'r.atencion_reviewed_by_id',
                'r.atencion_reviewed_at',
                'r.pedagogia_observations',
                'r.pedagogia_reviewed_by_id',
                'r.pedagogia_reviewed_at',
                'r.locked_at',
                'r.locked_by_id',
                'r.notified_by_id',
                'r.notified_at',
                'r.notification_method',
                'r.attendance_penalty_override',
                'r.created_at',
                'r.updated_at',
            ]);

        if ($refrends->isEmpty()) {
            return [
                'rows' => [],
                'meta' => [
                    'total'        => 0,
                    'per_page'     => $perPage,
                    'current_page' => $page,
                    'last_page'    => 1,
                ],
            ];
        }

        $userIds = $refrends->pluck('user_id')->unique()->values()->all();

        // ── Attendance aggregates ──────────────────────────────────────────
        // Semester-level: from semester start to today (for lates + totals).
        // Month-level: from period month start to today (for absence penalty trigger).
        $semesterStart  = $month <= 7 ? "{$year}-01-01" : "{$year}-08-01";
        $monthPadded    = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $periodMonthStart = "{$year}-{$monthPadded}-01";

        $attendanceRows = DB::table('attendances as a')
            ->join('classes as c', 'c.id', '=', 'a.class_id')
            ->whereIn('a.user_id', $userIds)
            ->where('c.date', '>=', $semesterStart)
            ->where('c.date', '<=', DB::raw('CURDATE()'))
            ->select([
                'a.user_id',
                DB::raw("SUM(CASE WHEN a.status = 'PRESENT' THEN 1 ELSE 0 END) as present_count"),
                DB::raw("SUM(CASE WHEN a.status = 'LATE' THEN 1 ELSE 0 END) as late_count"),
                DB::raw("SUM(CASE WHEN a.status = 'JUSTIFIED_LATE' THEN 1 ELSE 0 END) as late_justified_count"),
                DB::raw("SUM(CASE WHEN a.status = 'LATE' AND a.late_penalty_consumed = 1 THEN 1 ELSE 0 END) as late_consumed_count"),
                DB::raw("SUM(CASE WHEN a.status = 'LATE' AND a.late_penalty_consumed = 0 THEN 1 ELSE 0 END) as late_unconsumed_count"),
                DB::raw("SUM(CASE WHEN a.status = 'ABSENT' THEN 1 ELSE 0 END) as absent_count"),
                DB::raw("SUM(CASE WHEN a.status = 'JUSTIFIED_ABSENCE' THEN 1 ELSE 0 END) as absent_justified_count"),
                DB::raw("COUNT(*) as total_count"),
                // Month-level unjustified absences — direct payment suspension trigger.
                DB::raw("SUM(CASE WHEN a.status = 'ABSENT' AND c.date >= '{$periodMonthStart}' THEN 1 ELSE 0 END) as month_absent_count"),
            ])
            ->groupBy('a.user_id')
            ->get()
            ->keyBy('user_id');


        // ── Latest semester grade per user ─────────────────────────────────
        // Pick the most recent semester grade (highest year, then period desc)
        $gradeRows = DB::table('scholarship_semester_grades as g')
            ->whereIn('g.user_id', $userIds)
            ->select(['g.user_id', 'g.grade', 'g.semester_year', 'g.semester_period'])
            ->orderByDesc('g.semester_year')
            ->orderByDesc('g.semester_period')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first());

        // ── Attendance discounts already applied to each refrend ──────────
        // Used by the frontend Impacto column — more accurate than live counts
        // because lates get consumed (flagged) after the penalty is applied.
        $refrendIds = $refrends->pluck('id')->all();

        $attendanceDiscounts = DB::table('scholarship_refrend_discounts')
            ->whereIn('scholarship_refrend_id', $refrendIds)
            ->whereIn('discount_type', ['RETARDOS', 'FALTA_INJUSTIFICADA'])
            ->select(['scholarship_refrend_id', 'discount_type'])
            ->get()
            ->groupBy('scholarship_refrend_id');

        // ── Incidents from the incidents table ─────────────────────────────

        $incidentCounts = DB::table('scholarship_refrend_incidents')
            ->whereIn('scholarship_refrend_id', $refrendIds)
            ->select('scholarship_refrend_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('scholarship_refrend_id')
            ->get()
            ->keyBy('scholarship_refrend_id');

        // First unresolved incident per refrend (for the tooltip)
        $firstIncidents = DB::table('scholarship_refrend_incidents')
            ->whereIn('scholarship_refrend_id', $refrendIds)
            ->where('is_resolved', false)
            ->orderBy('created_at')
            ->select('scholarship_refrend_id', 'incident_category', 'incident_type', 'description')
            ->get()
            ->groupBy('scholarship_refrend_id')
            ->map(fn($rows) => $rows->first());

        // ── Assemble rows ──────────────────────────────────────────────────
        $rows = $refrends->map(function ($r) use ($attendanceRows, $gradeRows, $incidentCounts, $firstIncidents, $attendanceDiscounts) {
            $att          = $attendanceRows->get($r->user_id);
            $gradeRecord  = $gradeRows->get($r->user_id);
            $incident     = $firstIncidents->get($r->id);
            $grade        = $gradeRecord ? (float) $gradeRecord->grade : null;
            $discTypes    = $attendanceDiscounts->get($r->id)?->pluck('discount_type') ?? collect();

            $academicStatus = $this->resolveAcademicStatus($grade, $gradeRecord !== null);

            $atencionLabels = null;
            if ($r->atencion_labels !== null) {
                $decoded = json_decode($r->atencion_labels, true);
                $atencionLabels = is_array($decoded) ? $decoded : null;
            }

            $refrend = [
                'id'                       => $r->id,
                'user_id'                  => $r->user_id,
                'period_year'              => $r->period_year,
                'period_month'             => $r->period_month,
                'refrend_type'             => $r->refrend_type,
                'status'                   => $r->status,
                'workflow_status'          => $r->workflow_status,
                'resolution_type'          => $r->resolution_type ?? null,
                'resolution_cause'         => $r->resolution_cause ?? null,
                'resolution_notes'         => $r->resolution_notes ?? null,
                'suspension_percentage'    => $r->suspension_percentage ?? null,
                'base_amount'                  => $r->base_amount,
                'snapshot_discount_percentage' => $r->snapshot_discount_percentage ?? null,
                'snapshot_discount_reason'     => $r->snapshot_discount_reason ?? null,
                'discount_percentage'          => $r->discount_percentage,
                'discount_amount'          => $r->discount_amount,
                'final_amount'             => $r->final_amount,
                'amount_pending_from_previous' => $r->amount_pending_from_previous,
                'total_to_pay'             => (float) $r->final_amount + (float) ($r->amount_pending_from_previous ?? 0),
                'snapshot_name'            => $r->snapshot_name,
                'snapshot_generation'      => $r->snapshot_generation,
                'snapshot_generation_id'   => $r->snapshot_generation_id,
                'snapshot_campus'          => $r->snapshot_campus,
                'snapshot_scholarship_type' => $r->snapshot_scholarship_type,
                'atencion_observations'    => $r->atencion_observations,
                'atencion_labels'          => $atencionLabels,
                'atencion_reviewed_by_id'  => $r->atencion_reviewed_by_id,
                'atencion_reviewed_at'     => $r->atencion_reviewed_at,
                'pedagogia_observations'   => $r->pedagogia_observations,
                'pedagogia_reviewed_by_id' => $r->pedagogia_reviewed_by_id,
                'pedagogia_reviewed_at'    => $r->pedagogia_reviewed_at,
                'locked_at'                => $r->locked_at,
                'locked_by_id'             => $r->locked_by_id,
                'notified_by_id'           => $r->notified_by_id,
                'notified_at'              => $r->notified_at,
                'notification_method'      => $r->notification_method,
                'created_at'               => $r->created_at,
                'updated_at'               => $r->updated_at,
            ];

            return [
                'refrend'                       => $refrend,
                'attendance_present'            => (int) ($att->present_count ?? 0),
                'attendance_late'               => (int) ($att->late_count ?? 0),
                'attendance_late_justified'     => (int) ($att->late_justified_count ?? 0),
                'attendance_late_consumed'      => (int) ($att->late_consumed_count ?? 0),
                'attendance_late_unconsumed'    => (int) ($att->late_unconsumed_count ?? 0),
                'attendance_absent'             => (int) ($att->absent_count ?? 0),
                'attendance_absent_justified'   => (int) ($att->absent_justified_count ?? 0),
                'attendance_total'              => (int) ($att->total_count ?? 0),
                'last_grade'                    => $grade !== null ? number_format($grade, 2) : null,
                'academic_status'               => $academicStatus,
                'active_discount_pct'           => $r->discount_percentage,
                'projected_amount'              => $r->final_amount,
                'incidents_count'               => (int) ($incidentCounts->get($r->id)->cnt ?? 0),
                'incident_description'          => $incident?->description,
                'incident_category'             => $incident?->incident_category,
                'incident_type'                 => $incident?->incident_type,
                'semester_lates_unconsumed'     => (int) ($att->late_unconsumed_count ?? 0),
                'month_absent'                  => (int) ($att->month_absent_count ?? 0),
                'has_retardos_discount'         => $discTypes->contains('RETARDOS'),
                'has_falta_discount'            => $discTypes->contains('FALTA_INJUSTIFICADA'),
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil(max($total, 1) / $perPage),
            ],
        ];
    }

    private function resolveAcademicStatus(?float $grade, bool $hasGrade): string
    {
        if (!$hasGrade) {
            return 'missing_subjects';
        }

        if ($grade === null) {
            return 'missing_subjects';
        }

        if ($grade < 8.0) {
            return 'low_grade';
        }

        return 'ok';
    }
}
