<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Scholarship\ApproveFullPaymentAction;
use App\Actions\Scholarship\ApproveRefrendAction;
use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Actions\Scholarship\ClearRefrendIncidentAction;
use App\Actions\Scholarship\BulkApproveAction;
use App\Actions\Scholarship\BulkNotifyAction;
use App\Actions\Scholarship\DischargeScholarshipAction;
use App\Actions\Scholarship\FlagRefrendIncidentAction;
use App\Actions\Scholarship\NotifyStudentAction;
use App\Actions\Scholarship\ResolvePedagogiaAction;
use App\Enums\RefrendStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\InlineUpdateScholarshipRefrendRequest;
use App\Models\Attendance;
use App\Models\ScholarshipLateConsumption;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendIncident;
use App\Services\AttendancePenaltyService;
use App\Services\GenerateMonthlyRefrendsService;
use App\Services\RecalculateRefrendService;
use App\Services\RefrendBulkQueryService;
use App\Services\ScholarshipLoggingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScholarshipRefrendController extends Controller
{
    private GenerateMonthlyRefrendsService $generateService;
    private AttendancePenaltyService $penaltyService;
    private ScholarshipLoggingService $loggingService;
    private RecalculateRefrendService $recalculateService;

    public function __construct(
        GenerateMonthlyRefrendsService $generateService,
        AttendancePenaltyService $penaltyService,
        ScholarshipLoggingService $loggingService,
        RecalculateRefrendService $recalculateService
    ) {
        $this->generateService    = $generateService;
        $this->penaltyService     = $penaltyService;
        $this->loggingService     = $loggingService;
        $this->recalculateService = $recalculateService;
    }

    /**
     * Lista refrendos del mes actual (o mes/año indicado).
     */
    public function index(Request $request)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        $query = ScholarshipRefrend::with(['user', 'discounts'])->forPeriod($year, $month);

        if ($campus = $request->query('campus')) {
            $query->where('snapshot_campus', $campus);
        }

        if ($generationId = $request->query('generation_id')) {
            $query->forGeneration((int) $generationId);
        }

        return response()->json(['res' => true, 'data' => $query->get()]);
    }

    /**
     * Refrendos de un becario específico.
     */
    public function forUser(int $userId)
    {
        $refrends = ScholarshipRefrend::with(['discounts', 'atencionReviewedBy', 'pedagogiaReviewedBy'])
            ->where('user_id', $userId)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get();

        return response()->json(['res' => true, 'data' => $refrends]);
    }

    /**
     * Detalle de un refrendo.
     */
    public function show(ScholarshipRefrend $refrend): JsonResponse
    {
        $refrend->load([
            'user',
            'discounts.lateConsumptions.attendance',
            'adjustments',
            'logs.performedBy',
            'atencionReviewedBy',
            'pedagogiaReviewedBy',
            'incidents.createdBy',
            'incidents.resolvedBy',
        ]);

        return response()->json(['res' => true, 'data' => $refrend]);
    }

    /**
     * Genera refrendos DRAFT para el periodo indicado (idempotente).
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'year'          => 'required|integer|min:2020|max:2100',
            'month'         => 'required|integer|min:1|max:12',
            'campus'        => 'required|string|max:20',
            'generation_id' => 'required|integer|exists:generations,id',
        ]);

        $stats = $this->generateService->generateForPeriod(
            $data['year'],
            $data['month'],
            $data['campus'] ?? null,
            (int) $data['generation_id'],
        );

        return response()->json(['res' => true, 'data' => $stats]);
    }

    /**
     * Genera el refrendo de un becario específico para el periodo indicado.
     */
    public function generateForUser(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $profile = ScholarshipProfile::where('user_id', $userId)->firstOrFail();

        try {
            $refrend = $this->generateService->generateForUser($profile, $data['year'], $data['month']);
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        if ($refrend === null) {
            return response()->json(['res' => false, 'msg' => 'Ya existe un refrendo para este periodo.'], 409);
        }

        return response()->json(['res' => true, 'data' => $refrend], 201);
    }

    /**
     * Inline partial update for a refrend (atencion fields, pedagogia observations, amount override).
     */
    public function patchInline(InlineUpdateScholarshipRefrendRequest $request, ScholarshipRefrend $refrend): JsonResponse
    {

        if ($refrend->isLocked()) {
            return response()->json(['res' => false, 'msg' => 'El refrendo está bloqueado.'], 422);
        }

        $data    = $request->validated();
        $updates = [];
        $touchedAtencion = false;

        if (array_key_exists('atencion_labels', $data)) {
            $updates['atencion_labels'] = $data['atencion_labels'];
            $touchedAtencion = true;
        }
        if (array_key_exists('atencion_observations', $data)) {
            $updates['atencion_observations'] = $data['atencion_observations'];
            $touchedAtencion = true;
        }
        if ($touchedAtencion) {
            $updates['atencion_reviewed_by_id'] = auth()->id();
            $updates['atencion_reviewed_at']    = now();
        }
        if (array_key_exists('pedagogia_observations', $data)) {
            $updates['pedagogia_observations']   = $data['pedagogia_observations'];
            $updates['pedagogia_reviewed_by_id'] = auth()->id();
            $updates['pedagogia_reviewed_at']    = now();
        }
        if (array_key_exists('notification_method', $data)) {
            $updates['notification_method'] = $data['notification_method'];
        }
        if (array_key_exists('notified_at', $data)) {
            $updates['notified_at'] = $data['notified_at'];
        }
        if (array_key_exists('final_amount_override', $data) && $data['final_amount_override'] !== null) {
            $updates['final_amount']        = $data['final_amount_override'];
            $updates['discount_percentage'] = 0;
            $updates['discount_amount']     = 0;
        }

        if (empty($updates)) {
            return response()->json(['res' => true, 'data' => $refrend], 200);
        }

        $old = $this->loggingService->snapshotRefrend($refrend);

        $fresh = \Illuminate\Support\Facades\DB::transaction(function () use ($refrend, $updates, $old) {
            $refrend->update($updates);
            $fresh = $refrend->fresh();

            $this->loggingService->log(
                $refrend,
                'inline_update',
                $old,
                $this->loggingService->snapshotRefrend($fresh),
                json_encode(array_keys($updates), JSON_UNESCAPED_UNICODE)
            );

            return $fresh;
        });

        return response()->json(['res' => true, 'data' => $fresh]);
    }

    /**
     * Resumen de asistencias de un becario en un periodo mensual.
     */
    public function attendanceSummary(Request $request, int $userId)
    {
        $data = $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year  = $data['year'];
        $month = $data['month'];

        $referenceDate  = Carbon::create($year, $month, 1);
        $semesterBounds = $this->penaltyService->getCurrentSemesterBounds($referenceDate);

        $attendances = Attendance::with('class')
            ->where('user_id', $userId)
            ->whereHas('class', fn($q) => $q->whereBetween('date', [$semesterBounds['start'], $semesterBounds['end']]))
            ->get();

        $unconsumedLates = $this->penaltyService->getUnconsumedLatesForUser(
            \App\Models\User::findOrFail($userId),
            Carbon::create($year, $month, 1)
        );

        $consumedSet = array_flip(
            ScholarshipLateConsumption::whereIn('attendance_id', $attendances->pluck('id')->all())
                ->pluck('attendance_id')
                ->all()
        );

        $summary = [
            'total'               => $attendances->count(),
            'present'             => $attendances->where('status', 'PRESENT')->count(),
            'late'                => $attendances->where('status', 'LATE')->count(),
            'late_justified'      => $attendances->where('status', 'JUSTIFIED_LATE')->count(),
            'late_consumed'       => count($consumedSet),
            'late_unconsumed'     => $unconsumedLates->count(),
            'absent_unjustified'  => $attendances->where('status', 'ABSENT')->count(),
            'absent_justified'    => $attendances->whereIn('status', ['JUSTIFIED', 'JUSTIFIED_ABSENCE'])->count(),
            'semester_start'      => $semesterBounds['start'],
            'semester_end'        => $semesterBounds['end'],
            'records'             => $attendances->map(fn($a) => [
                'id'                    => $a->id,
                'class_date'            => $a->class?->date,
                'class_name'            => $a->class?->name,
                'status'                => $a->status,
                'observations'          => $a->observations,
                'late_penalty_consumed' => array_key_exists($a->id, $consumedSet),
            ])->sortBy('class_date')->values(),
        ];

        return response()->json(['res' => true, 'data' => $summary]);
    }

    /**
     * Returns the paginated bulk master table for a given period.
     */
    public function bulkTable(Request $request, RefrendBulkQueryService $service): JsonResponse
    {
        $data = $request->validate([
            'year'                  => 'required|integer|min:2020|max:2100',
            'month'                 => 'required|integer|min:1|max:12',
            'campus'                => 'required|string|max:20',
            'generation_id'         => 'nullable|integer|exists:generations,id',
            'page'          => 'nullable|integer|min:1',
            'per_page'      => 'nullable|integer|min:1|max:500',
        ]);

        $result = $service->buildTable(
            $data['year'],
            $data['month'],
            $data['campus'],
            isset($data['generation_id']) ? (int) $data['generation_id'] : null,
            $data['page'] ?? 1,
            $data['per_page'] ?? 200,
        );

        return response()->json([
            'res'  => true,
            'data' => $result['rows'],
            'meta' => $result['meta'],
        ]);
    }

    // ── New workflow endpoints ─────────────────────────────────────────────────

    /** Removes all auto-discounts, forces final = base, advances to LISTO_PARA_PAGO. */
    public function approveFullPayment(ScholarshipRefrend $refrend): JsonResponse
    {
        try {
            $updated = app(ApproveFullPaymentAction::class)->execute($refrend, auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated]);
    }

    /**
     * Records the payment situation for a refrend, advancing it to LISTO_PARA_PAGO.
     * Applies to DRAFT and LISTO_PARA_PAGO refrendos (allows re-classification).
     */
    public function recordSituation(Request $request, ScholarshipRefrend $refrend): JsonResponse
    {
        // Same reference amount RecordPaymentSituationAction uses: gross snapshot with
        // the active profile discount applied, not the raw base_amount.
        $gross       = (float) ($refrend->snapshot_gross_amount ?? $refrend->base_amount);
        $academicPct = (float) ($refrend->snapshot_discount_percentage ?? 0);
        $dueAmount   = round($gross * (1 - $academicPct / 100), 2);

        $data = $request->validate([
            'resolution_type'         => 'required|in:BECA_MES,SIN_PAGO,RETENIDA,SUSPENDIDA,BAJA_DEFINITIVA,EGRESADO,REEMBOLSO_PARCIAL',
            'resolution_cause'        => 'nullable|string|max:200',
            'resolution_notes'        => 'required_if:resolution_type,REEMBOLSO_PARCIAL|nullable|string|max:1000',
            'suspension_percentage'   => 'required_if:resolution_type,SUSPENDIDA|nullable|numeric|in:25,30,50,65,75,100',
            'refund_amount'           => 'required_if:resolution_type,REEMBOLSO_PARCIAL|nullable|numeric|min:0.01',
            'withholding_mode'        => 'required_if:resolution_type,RETENIDA|nullable|in:percentage,fixed',
            'withholding_value'       => [
                'required_if:resolution_type,RETENIDA',
                'nullable',
                'numeric',
                'min:0.01',
                function ($attribute, $value, $fail) use ($request, $dueAmount) {
                    if ($request->input('resolution_type') !== 'RETENIDA' || $value === null) {
                        return;
                    }
                    $mode = $request->input('withholding_mode');
                    if ($mode === 'percentage' && (float) $value > 100) {
                        $fail('El porcentaje de retención no puede superar 100.');
                    }
                    if ($mode === 'fixed' && (float) $value > $dueAmount) {
                        $fail('El monto fijo de retención no puede superar el monto a pagar.');
                    }
                },
            ],
            'carryover_months_count'   => 'nullable|integer|min:1|max:12',
            'carryover_months_detail'  => 'nullable|string|max:500',
            'carryover_percentage'     => 'nullable|numeric|min:1|max:100',
        ]);

        try {
            $updated = app(RecordPaymentSituationAction::class)->execute($refrend, $data, auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated]);
    }

    /**
     * Atención approves a DRAFT refrend → LISTO_PARA_PAGO (no incidents).
     */
    public function atencionApprove(ScholarshipRefrend $refrend): JsonResponse
    {
        try {
            $updated = app(ApproveRefrendAction::class)->execute($refrend, auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated]);
    }

    /**
     * Clears the active incident on a CON_INCIDENCIA refrend → reverts to DRAFT.
     */
    public function atencionClearFlag(ScholarshipRefrend $refrend): JsonResponse
    {
        try {
            $updated = app(ClearRefrendIncidentAction::class)->execute($refrend, auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated]);
    }

    /**
     * Atención flags a DRAFT refrend with an incident → CON_INCIDENCIA.
     */
    public function atencionFlag(Request $request, ScholarshipRefrend $refrend): JsonResponse
    {
        $data = $request->validate([
            'incident_category' => 'required|in:ASISTENCIA,ACADEMICO,DOCUMENTOS,ADMINISTRATIVO,OTRO',
            'incident_type'     => 'required|string|max:100',
            'description'       => 'required|string|max:1000',
            'priority'          => 'required|in:LOW,MEDIUM,HIGH,CRITICAL',
            'incident_date'     => 'nullable|date',
            'comment'           => 'nullable|string|max:2000',
        ]);

        try {
            $updated = app(FlagRefrendIncidentAction::class)->execute(
                $refrend,
                [
                    'incident_category' => $data['incident_category'],
                    'incident_type'     => $data['incident_type'],
                    'description'       => $data['description'],
                    'priority'          => $data['priority'],
                    'incident_date'     => $data['incident_date'] ?? null,
                ],
                $data['comment'] ?? '',
                auth()->id()
            );
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated], 201);
    }

    /**
     * Pedagogía adds a comment to a CON_INCIDENCIA refrend.
     * Does not change workflow_status — action is decided via the situation buttons.
     */
    public function pedagogiaResolve(Request $request, ScholarshipRefrend $refrend): JsonResponse
    {
        $data = $request->validate([
            'comment' => 'nullable|string|max:2000',
        ]);

        try {
            $updated = app(ResolvePedagogiaAction::class)->execute($refrend, $data, auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated]);
    }

    /**
     * Notifies the student and advances the refrend from PENDIENTE_NOTIFICACION.
     */
    public function notifyStudent(Request $request, ScholarshipRefrend $refrend): JsonResponse
    {
        $data = $request->validate([
            'notification_method' => 'nullable|string|max:50',
        ]);

        try {
            $updated = app(NotifyStudentAction::class)->execute(
                $refrend,
                $data['notification_method'] ?? null,
                auth()->id()
            );
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated]);
    }

    /**
     * Bulk-approves multiple DRAFT refrends → LISTO_PARA_PAGO.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:scholarship_refrends,id',
        ]);

        $result = app(BulkApproveAction::class)->execute($request->ids, auth()->id());

        return response()->json(['res' => true, 'data' => $result]);
    }

    /**
     * Bulk-notifies multiple PENDIENTE_NOTIFICACION refrends.
     */
    public function bulkNotify(Request $request): JsonResponse
    {
        $request->validate([
            'ids'                 => 'required|array|min:1',
            'ids.*'               => 'integer|exists:scholarship_refrends,id',
            'notification_method' => 'nullable|string|max:50',
        ]);

        $result = app(BulkNotifyAction::class)->execute(
            $request->ids,
            $request->notification_method ?? null,
            auth()->id()
        );

        return response()->json(['res' => true, 'data' => $result]);
    }

    /**
     * Marks multiple LISTO_PARA_PAGO refrends as CLOSED (administrative close).
     */
    public function bulkPay(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:scholarship_refrends,id',
        ]);

        $refrends = ScholarshipRefrend::whereIn('id', $request->ids)
            ->where('workflow_status', 'LISTO_PARA_PAGO')
            ->where(function ($q) {
                $q->where('status', '!=', RefrendStatus::WITHHELD->value)
                    ->orWhere('final_amount', '>', 0);
            })
            ->get();

        foreach ($refrends as $refrend) {
            $old = $this->loggingService->snapshotRefrend($refrend);
            $refrend->update([
                'status'          => RefrendStatus::PAID->value,
                'workflow_status' => 'CLOSED',
                'locked_at'       => now(),
                'locked_by_id'    => auth()->id(),
            ]);
            $this->loggingService->log(
                $refrend,
                'BULK_PAY',
                $old,
                $this->loggingService->snapshotRefrend($refrend->fresh())
            );
        }

        return response()->json(['res' => true, 'data' => ['paid' => $refrends->count()]]);
    }

    // ── Incidents ─────────────────────────────────────────────────────────────

    /**
     * Adds a new incident to an unlocked refrend.
     */
    public function createIncident(Request $request, ScholarshipRefrend $refrend): JsonResponse
    {
        if ($refrend->isLocked()) {
            return response()->json(['res' => false, 'msg' => 'Refrendo cerrado, no se pueden agregar incidencias.'], 422);
        }

        $data = $request->validate([
            'incident_category' => 'required|in:ASISTENCIA,ACADEMICO,DOCUMENTOS,ADMINISTRATIVO,OTRO',
            'incident_type'     => 'required|string|max:100',
            'incident_date'     => 'nullable|date',
            'description'       => 'required|string|max:1000',
        ]);

        $incident = $refrend->incidents()->create([
            ...$data,
            'created_by_id' => auth()->id(),
        ]);

        $this->loggingService->log(
            $refrend,
            'INCIDENT_ADDED',
            [],
            ['incident_type' => $data['incident_type']]
        );

        return response()->json(['res' => true, 'data' => $incident], 201);
    }

    /**
     * Deletes an incident from an unlocked refrend.
     */
    public function deleteIncident(ScholarshipRefrend $refrend, ScholarshipRefrendIncident $incident): JsonResponse
    {
        if ($refrend->isLocked()) {
            return response()->json(['res' => false, 'msg' => 'Refrendo cerrado.'], 422);
        }

        $this->loggingService->log(
            $refrend,
            'INCIDENT_REMOVED',
            ['incident_type' => $incident->incident_type],
            []
        );

        $incident->delete();

        return response()->json(null, 204);
    }

    /**
     * Marks an incident as resolved.
     */
    public function resolveIncident(Request $request, ScholarshipRefrend $refrend, ScholarshipRefrendIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'resolution_notes' => 'required|string|min:10|max:500',
        ]);

        $incident->update([
            'is_resolved'      => true,
            'resolved_at'      => now(),
            'resolved_by_id'   => auth()->id(),
            'resolution_notes' => $data['resolution_notes'],
        ]);

        $this->loggingService->log(
            $refrend,
            'INCIDENT_RESOLVED',
            ['is_resolved' => false],
            ['is_resolved' => true, 'resolution_notes' => $data['resolution_notes']]
        );

        return response()->json(['res' => true, 'data' => $incident->fresh()]);
    }

    // ── Recalculate ───────────────────────────────────────────────────────────

    /**
     * Full recalculation: refreshes all snapshots, clears and re-applies
     * automatic discounts, then recalculates final amount. DRAFT only.
     */
    public function recalculate(ScholarshipRefrend $refrend): JsonResponse
    {
        try {
            $updated = $this->recalculateService->fullRecalculate($refrend);
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated->load('discounts')]);
    }

    // ── Discharge ─────────────────────────────────────────────────────────────

    /**
     * Discharges a scholar via DischargeScholarshipAction.
     */
    public function discharge(Request $request, ScholarshipRefrend $refrend): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:20|max:1000',
        ]);

        try {
            $updated = app(DischargeScholarshipAction::class)->execute($refrend, $request->reason, auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $updated]);
    }
}
