<?php

namespace App\Services;

use App\Services\Scholarship\PayableWithholdingWindow;
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
            ->leftJoin('scholarship_profiles as sp', 'sp.user_id', '=', 'r.user_id')
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
                'r.snapshot_gross_amount',
                'r.snapshot_monto_apoyo',
                'r.base_amount',
                'r.snapshot_discount_percentage',
                'r.snapshot_discount_reason',
                'r.discount_percentage',
                'r.discount_amount',
                'r.final_amount',
                'r.amount_pending_from_previous',
                'r.refund_amount_from_previous',
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
                'r.attendance_summary_snapshot',
                'r.created_at',
                'r.updated_at',
                'sp.active_discount_percentage as profile_discount_pct',
                'sp.discount_valid_until as profile_discount_valid_until',
                'sp.discount_reason as profile_discount_reason',
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
            ->where('discount_percentage', '>', 0)
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

        // ── Retenciones pagables (ventana 3 meses / top-2) por becario ──────
        // El chip refleja solo el subconjunto pagable, no el histórico crudo
        // (mismo set-operation D2 que el endpoint de listado y las
        // validaciones — evita reimplementar el ranking). SUM/COUNT GROUP BY
        // no puede expresar top-N-por-grupo, y las funciones de ventana de
        // MySQL 8 no son portables al driver SQLite de pruebas, así que se
        // trae un fetch acotado (≤4 filas/becario: ventana de 4 periodos y
        // unique(origin_refrend_id) + un refrendo por user/periodo ⇒ ≤1 fila
        // por periodo) en una sola query (O(1), usa el índice
        // ['user_id','status']) y se rankea/suma en PHP. El monto se emite
        // como string decimal en el armado de la fila para no depender del
        // tipo que devuelva el driver (MySQL: string, SQLite: float).
        [$minAbsMonth, $maxAbsMonth] = PayableWithholdingWindow::absoluteBounds($year, $month);
        $pendingWithholdings = DB::table('scholarship_withholdings')
            ->whereIn('user_id', $userIds)
            ->where('status', 'PENDING')
            ->whereColumn('paid_amount', '<', 'withheld_amount')
            ->whereRaw('(period_year * 12 + period_month) between ? and ?', [$minAbsMonth, $maxAbsMonth])
            ->select(['id', 'user_id', 'period_year', 'period_month', 'withheld_amount', 'paid_amount'])
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => PayableWithholdingWindow::selectPayable($rows, $year, $month));

        // ── Assemble rows ──────────────────────────────────────────────────
        $rows = $refrends->map(function ($r) use ($gradeRows, $incidentCounts, $firstIncidents, $attendanceDiscounts, $pendingWithholdings) {
            $snap         = $r->attendance_summary_snapshot ? json_decode($r->attendance_summary_snapshot, true) : null;
            $gradeRecord  = $gradeRows->get($r->user_id);
            $incident     = $firstIncidents->get($r->id);
            $grade        = $gradeRecord ? (float) $gradeRecord->grade : null;
            $discTypes    = $attendanceDiscounts->get($r->id)?->pluck('discount_type') ?? collect();

            $payable       = $pendingWithholdings->get($r->user_id) ?? collect();
            $pendingCount  = $payable->count();
            $pendingAmount = $pendingCount > 0
                ? number_format(
                    $payable->sum(fn ($w) => max(0, (float) $w->withheld_amount - (float) $w->paid_amount)),
                    2,
                    '.',
                    ''
                )
                : null;

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
                'snapshot_gross_amount'        => $r->snapshot_gross_amount,
                'snapshot_monto_apoyo'         => $r->snapshot_monto_apoyo,
                'base_amount'                  => $r->base_amount,
                'snapshot_discount_percentage' => $r->snapshot_discount_percentage ?? null,
                'snapshot_discount_reason'     => $r->snapshot_discount_reason ?? null,
                'discount_percentage'          => $r->discount_percentage,
                'discount_amount'          => $r->discount_amount,
                'final_amount'             => $r->final_amount,
                'amount_pending_from_previous' => $r->amount_pending_from_previous,
                'refund_amount_from_previous'   => $r->refund_amount_from_previous ?? '0.00',
                'total_to_pay'             => (float) $r->final_amount + (float) ($r->amount_pending_from_previous ?? 0) + (float) ($r->refund_amount_from_previous ?? 0),
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
                'attendance_present'            => (int) ($snap['present'] ?? 0),
                'attendance_late'               => (int) ($snap['late'] ?? 0),
                'attendance_late_justified'     => 0,
                'attendance_late_consumed'      => 0,
                'attendance_late_unconsumed'    => (int) ($snap['late_unconsumed'] ?? 0),
                'attendance_absent'             => (int) ($snap['absent'] ?? 0),
                'attendance_absent_justified'   => 0,
                'attendance_total'              => (int) ($snap['total'] ?? 0),
                'last_grade'                    => $grade !== null ? number_format($grade, 2) : null,
                'academic_status'               => $academicStatus,
                'active_discount_pct'           => $r->discount_percentage,
                'projected_amount'              => $r->final_amount,
                'incidents_count'               => (int) ($incidentCounts->get($r->id)->cnt ?? 0),
                'incident_description'          => $incident?->description,
                'incident_category'             => $incident?->incident_category,
                'incident_type'                 => $incident?->incident_type,
                'semester_lates_unconsumed'     => (int) ($snap['late_unconsumed'] ?? 0),
                'month_absent'                  => (int) ($snap['month_absent'] ?? 0),
                'has_retardos_discount'         => $discTypes->contains('RETARDOS'),
                'has_falta_discount'            => $discTypes->contains('FALTA_INJUSTIFICADA'),
                'profile_discount_pct'          => $r->profile_discount_pct,
                'profile_discount_valid_until'  => $r->profile_discount_valid_until,
                'profile_discount_reason'       => $r->profile_discount_reason,
                'pending_withholding_count'     => $pendingCount,
                'pending_withholding_amount'    => $pendingAmount,
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
