<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateScholarshipRefrendReviewRequest;
use App\Models\Attendance;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Enums\RefrendStatus;
use App\Services\AttendancePenaltyService;
use App\Services\GenerateMonthlyRefrendsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScholarshipRefrendController extends Controller
{
    private GenerateMonthlyRefrendsService $generateService;
    private AttendancePenaltyService $penaltyService;

    public function __construct(
        GenerateMonthlyRefrendsService $generateService,
        AttendancePenaltyService $penaltyService
    ) {
        $this->generateService = $generateService;
        $this->penaltyService  = $penaltyService;
    }

    /**
     * Lista refrendos del mes actual (o mes/año indicado).
     */
    public function index(Request $request)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        $refrends = ScholarshipRefrend::with(['user', 'discounts'])
            ->forPeriod($year, $month)
            ->get();

        return response()->json(['res' => true, 'data' => $refrends]);
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
    public function show(int $id)
    {
        $refrend = ScholarshipRefrend::with([
            'user',
            'discounts.lateConsumptions.attendance',
            'adjustments',
            'logs.performedBy',
            'atencionReviewedBy',
            'pedagogiaReviewedBy',
        ])->findOrFail($id);

        return response()->json(['res' => true, 'data' => $refrend]);
    }

    /**
     * Genera refrendos DRAFT para el periodo indicado (idempotente).
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'year'  => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $stats = $this->generateService->generateForPeriod($data['year'], $data['month']);

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
     * Revisión de Atención de Becarios.
     */
    public function atencionReview(UpdateScholarshipRefrendReviewRequest $request, int $id)
    {
        $refrend = ScholarshipRefrend::findOrFail($id);

        if ($refrend->isLocked()) {
            return response()->json(['res' => false, 'msg' => 'El refrendo está bloqueado.'], 422);
        }

        $refrend->update([
            'status'                  => RefrendStatus::ATENCION_REVIEW->value,
            'atencion_observations'   => $request->validated()['observations'] ?? null,
            'atencion_reviewed_by_id' => auth()->id(),
            'atencion_reviewed_at'    => now(),
        ]);

        $this->log($refrend->id, 'atencion_review', $request->validated()['observations'] ?? null);

        return response()->json(['res' => true, 'data' => $refrend->fresh()]);
    }

    /**
     * Revisión de Pedagogía.
     */
    public function pedagogiaReview(UpdateScholarshipRefrendReviewRequest $request, int $id)
    {
        $refrend = ScholarshipRefrend::findOrFail($id);

        if ($refrend->isLocked()) {
            return response()->json(['res' => false, 'msg' => 'El refrendo está bloqueado.'], 422);
        }

        $refrend->update([
            'status'                    => RefrendStatus::PEDAGOGIA_REVIEW->value,
            'pedagogia_observations'    => $request->validated()['observations'] ?? null,
            'pedagogia_reviewed_by_id'  => auth()->id(),
            'pedagogia_reviewed_at'     => now(),
        ]);

        $this->log($refrend->id, 'pedagogia_review', $request->validated()['observations'] ?? null);

        return response()->json(['res' => true, 'data' => $refrend->fresh()]);
    }

    /**
     * Autorizar refrendo (Pedagogía aprueba el pago).
     * Acepta override opcional del monto final y notas de autorización.
     */
    public function approveRefrend(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'final_amount_override' => 'nullable|numeric|min:0',
            'authorization_notes'   => 'nullable|string|max:2000',
        ]);

        $refrend = ScholarshipRefrend::findOrFail($id);

        if ($refrend->isLocked()) {
            return response()->json(['res' => false, 'msg' => 'El refrendo ya está bloqueado.'], 422);
        }

        // Revalidar que el periodo siga dentro de la retícula al momento de autorizar
        $profile = ScholarshipProfile::where('user_id', $refrend->user_id)->first();
        if ($profile && $profile->reticula_end_date) {
            $periodStart = Carbon::create($refrend->period_year, $refrend->period_month, 1);
            if ($periodStart->gt($profile->reticula_end_date)) {
                return response()->json([
                    'res' => false,
                    'msg' => "No se puede autorizar: el periodo académico del becario finalizó el {$profile->reticula_end_date->toDateString()}.",
                ], 422);
            }
        }

        $updates = [
            'status'       => RefrendStatus::AUTHORIZED->value,
            'locked_at'    => now(),
            'locked_by_id' => auth()->id(),
        ];

        if (array_key_exists('final_amount_override', $data) && $data['final_amount_override'] !== null) {
            $updates['final_amount']        = $data['final_amount_override'];
            $updates['discount_percentage'] = 0;
            $updates['discount_amount']     = 0;
        }

        $refrend->update($updates);

        $notes = $data['authorization_notes'] ?? null;
        $this->log($refrend->id, 'authorized', $notes);

        return response()->json(['res' => true, 'data' => $refrend->fresh()]);
    }

    /**
     * Marcar como pagado.
     */
    public function markPaid(int $id)
    {
        $refrend = ScholarshipRefrend::findOrFail($id);

        if ($refrend->status !== RefrendStatus::AUTHORIZED) {
            return response()->json(['res' => false, 'msg' => 'El refrendo debe estar autorizado antes de marcarse como pagado.'], 422);
        }

        $refrend->update([
            'status'    => RefrendStatus::PAID->value,
            'locked_at' => now(),
            'locked_by_id' => auth()->id(),
        ]);

        $this->log($refrend->id, 'paid');

        return response()->json(['res' => true, 'data' => $refrend->fresh()]);
    }

    /**
     * Retener refrendo.
     */
    public function withhold(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:1000']);

        $refrend = ScholarshipRefrend::findOrFail($id);

        if ($refrend->isLocked()) {
            return response()->json(['res' => false, 'msg' => 'El refrendo está bloqueado.'], 422);
        }

        $refrend->update(['status' => RefrendStatus::WITHHELD->value]);

        $this->log($refrend->id, 'withheld', $data['reason'] ?? null);

        return response()->json(['res' => true, 'data' => $refrend->fresh()]);
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
        $start = Carbon::create($year, $month, 1)->startOfDay()->toDateString();
        $end   = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $attendances = Attendance::with('class')
            ->where('user_id', $userId)
            ->whereHas('class', fn($q) => $q->whereBetween('date', [$start, $end]))
            ->get();

        $semesterBounds = $this->penaltyService->getCurrentSemesterBounds(
            Carbon::create($year, $month, 1)
        );

        $unconsumedLates = $this->penaltyService->getUnconsumedLatesForUser(
            \App\Models\User::findOrFail($userId),
            Carbon::create($year, $month, 1)
        );

        $summary = [
            'total'               => $attendances->count(),
            'present'             => $attendances->where('status', 'PRESENT')->count(),
            'late'                => $attendances->where('status', 'LATE')->count(),
            'late_justified'      => $attendances->where('status', 'JUSTIFIED_LATE')->count(),
            'late_consumed'       => $attendances->where('late_penalty_consumed', true)->count(),
            'late_unconsumed'     => $unconsumedLates->count(),
            'absent_unjustified'  => $attendances->where('status', 'ABSENT')->count(),
            'absent_justified'    => $attendances->whereIn('status', ['JUSTIFIED', 'JUSTIFIED_ABSENCE'])->count(),
            'semester_start'      => $semesterBounds['start'],
            'semester_end'        => $semesterBounds['end'],
            'records'             => $attendances->map(fn($a) => [
                'id'                     => $a->id,
                'class_date'             => $a->class?->date,
                'status'                 => $a->status,
                'late_penalty_consumed'  => $a->late_penalty_consumed,
            ])->sortBy('class_date')->values(),
        ];

        return response()->json(['res' => true, 'data' => $summary]);
    }

    private function log(int $refrendId, string $action, ?string $notes = null): void
    {
        ScholarshipRefrendLog::create([
            'scholarship_refrend_id' => $refrendId,
            'performed_by_id'        => auth()->id(),
            'action'                 => $action,
            'notes'                  => $notes,
        ]);
    }
}
