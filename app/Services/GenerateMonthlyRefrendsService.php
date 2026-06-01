<?php

namespace App\Services;

use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateMonthlyRefrendsService
{
    private ScholarshipCalculationService $calculationService;
    private AttendancePenaltyService $penaltyService;

    public function __construct(
        ScholarshipCalculationService $calculationService,
        AttendancePenaltyService $penaltyService
    ) {
        $this->calculationService = $calculationService;
        $this->penaltyService     = $penaltyService;
    }

    /**
     * Genera refrendos DRAFT para todos los becarios activos con perfil de beca
     * para el periodo indicado. Idempotente: si ya existe, lo omite.
     *
     * @return array{created: int, skipped: int, errors: int}
     */
    public function generateForPeriod(
        int $year,
        int $month,
        ?string $campus = null,
        ?int $generationId = null
    ): array {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        /** @var \Illuminate\Support\Collection<int, ScholarshipProfile> $profiles */
        $profiles = ScholarshipProfile::with('user')
            ->whereHas('user', function ($q) use ($campus, $generationId) {
                $q->where('user_type', 'BEC_ACTIVE')->where('active', true);
                if ($campus !== null) {
                    $q->where('campus', $campus);
                }
                if ($generationId !== null) {
                    $q->where('generation_id', $generationId);
                }
            })
            ->get();

        foreach ($profiles as $profile) {
            try {
                $result = $this->generateForUser($profile, $year, $month);
                $result ? $stats['created']++ : $stats['skipped']++;
            } catch (\Throwable $e) {
                Log::error("Error generando refrendo para user_id={$profile->user_id}", [
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Genera el refrendo de un becario para el periodo dado.
     * Retorna el refrendo creado o null si ya existía.
     * Lanza DomainException si el periodo está fuera del rango de la retícula.
     */
    public function generateForUser(
        ScholarshipProfile $profile,
        int $year,
        int $month
    ): ?ScholarshipRefrend {
        // Verificar si ya existe el refrendo NORMAL para ese periodo
        $exists = ScholarshipRefrend::where('user_id', $profile->user_id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('refrend_type', RefrendType::NORMAL->value)
            ->exists();

        if ($exists) {
            return null;
        }

        // Validar que el periodo esté dentro del rango de la retícula
        $this->assertPeriodWithinReticula($profile, $year, $month);

        $snapshot      = $this->calculationService->buildSnapshot($profile);
        $referenceDate = Carbon::create($year, $month, 1);

        // Calcular monto arrastrado de refrendos WITHHELD previos no liberados
        $pendingFromPrevious = $this->calculatePendingCarryover($profile->user_id, $year, $month);

        // Freeze academic snapshot at generation time
        $lastGrade       = $this->getLastSemesterGrade($profile->user_id);
        $attendanceSummary = $this->getAttendanceSummaryForPeriod(
            $profile->user_id,
            $year,
            $month
        );

        return DB::transaction(function () use ($profile, $year, $month, $snapshot, $referenceDate, $pendingFromPrevious, $lastGrade, $attendanceSummary) {
            $refrend = ScholarshipRefrend::create([
                'user_id'                      => $profile->user_id,
                'period_year'                  => $year,
                'period_month'                 => $month,
                'refrend_type'                 => RefrendType::NORMAL->value,
                'status'                       => RefrendStatus::DRAFT->value,
                'workflow_status'              => 'DRAFT',
                'resolution_type'              => null,
                'base_amount'                  => $snapshot['base_amount'],
                'discount_percentage'          => 0,
                'discount_amount'              => 0,
                'final_amount'                 => $snapshot['base_amount'],
                'amount_pending_from_previous' => $pendingFromPrevious,
                'snapshot_name'                => $snapshot['snapshot_name'],
                'snapshot_generation'          => $snapshot['snapshot_generation'],
                'snapshot_generation_id'       => $snapshot['snapshot_generation_id'] ?? null,
                'snapshot_campus'              => $snapshot['snapshot_campus'],
                'snapshot_scholarship_type'    => $snapshot['snapshot_scholarship_type'],
                'average_grade_snapshot'       => $lastGrade,
                'missing_subjects_snapshot'    => 0,
                'attendance_summary_snapshot'  => $attendanceSummary,
            ]);

            // Aplicar descuento académico vigente si corresponde
            $this->calculationService->applyAcademicDiscount($refrend, $profile);

            // Evaluar penalizaciones automáticas de asistencia
            $user = $profile->user;
            $this->penaltyService->applyPenaltyIfDue($refrend, $user, 100.0, $referenceDate);
            $this->penaltyService->applyAbsencePenaltyIfDue($refrend, $user, $year, $month);

            // Recalcular montos finales
            $this->calculationService->recalculate($refrend);

            return $refrend->fresh();
        });
    }

    /**
     * Valida que el periodo (año/mes) esté dentro del rango de la retícula del perfil.
     * Lanza DomainException si está fuera del rango.
     */
    private function assertPeriodWithinReticula(ScholarshipProfile $profile, int $year, int $month): void
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();

        if ($profile->reticula_start_date && $periodStart->lt($profile->reticula_start_date)) {
            throw new \DomainException(
                "El periodo {$month}/{$year} es anterior al inicio de la retícula ({$profile->reticula_start_date->toDateString()})."
            );
        }

        if ($profile->reticula_end_date && $periodStart->gt($profile->reticula_end_date)) {
            throw new \DomainException(
                "El periodo {$month}/{$year} está fuera del periodo académico. La retícula finalizó el {$profile->reticula_end_date->toDateString()}."
            );
        }
    }

    /**
     * Returns the most recent semester grade for the user, or null if none exists.
     */
    private function getLastSemesterGrade(int $userId): ?float
    {
        $row = DB::table('scholarship_semester_grades')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->value('grade');

        return $row !== null ? (float) $row : null;
    }

    /**
     * Builds a light attendance summary for the given period month.
     * Used for freezing the snapshot at generation time.
     *
     * @return array{present: int, late: int, absent: int, late_unconsumed: int}
     */
    private function getAttendanceSummaryForPeriod(
        int $userId,
        int $year,
        int $month
    ): array {
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
            'present'        => $summary['present'],
            'late'           => $summary['late'],
            'absent'         => $summary['absent'],
            'late_unconsumed' => max(0, $summary['late'] - $summary['late_consumed']),
        ];
    }

    /**
     * Suma los montos finales de todos los refrendos WITHHELD previos del becario
     * que aún no han sido liberados (no tienen un refrendo PAID posterior).
     * Retorna el total acumulado pendiente.
     */
    private function calculatePendingCarryover(int $userId, int $year, int $month): float
    {
        // Obtener refrendos WITHHELD anteriores al periodo actual
        $withheld = ScholarshipRefrend::where('user_id', $userId)
            ->where('status', RefrendStatus::WITHHELD->value)
            ->where(function ($q) use ($year, $month) {
                $q->where('period_year', '<', $year)
                  ->orWhere(function ($q2) use ($year, $month) {
                      $q2->where('period_year', $year)->where('period_month', '<', $month);
                  });
            })
            ->get();

        if ($withheld->isEmpty()) {
            return 0.0;
        }

        // Descontar los que ya fueron incluidos en un refrendo anterior como arrastre
        // (si ya existe un refrendo PAID o AUTHORIZED con amount_pending_from_previous > 0,
        // ese arrastre ya fue cubierto).
        $alreadyCovered = ScholarshipRefrend::where('user_id', $userId)
            ->whereIn('status', [RefrendStatus::PAID->value, RefrendStatus::AUTHORIZED->value])
            ->where('amount_pending_from_previous', '>', 0)
            ->where(function ($q) use ($year, $month) {
                $q->where('period_year', '<', $year)
                  ->orWhere(function ($q2) use ($year, $month) {
                      $q2->where('period_year', $year)->where('period_month', '<', $month);
                  });
            })
            ->sum('amount_pending_from_previous');

        $totalWithheld = $withheld->sum(fn($r) => (float) $r->final_amount);
        $pending = max(0.0, round($totalWithheld - (float) $alreadyCovered, 2));

        return $pending;
    }
}
