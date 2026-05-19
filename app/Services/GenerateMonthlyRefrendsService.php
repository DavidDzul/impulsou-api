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
    public function generateForPeriod(int $year, int $month): array
    {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        /** @var \Illuminate\Support\Collection<int, ScholarshipProfile> $profiles */
        $profiles = ScholarshipProfile::with('user')
            ->whereHas('user', fn($q) => $q->where('user_type', 'BEC_ACTIVE')->where('active', true))
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
    public function generateForUser(ScholarshipProfile $profile, int $year, int $month): ?ScholarshipRefrend
    {
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

        return DB::transaction(function () use ($profile, $year, $month, $snapshot, $referenceDate, $pendingFromPrevious) {
            $refrend = ScholarshipRefrend::create([
                'user_id'                      => $profile->user_id,
                'period_year'                  => $year,
                'period_month'                 => $month,
                'refrend_type'                 => RefrendType::NORMAL->value,
                'status'                       => RefrendStatus::DRAFT->value,
                'base_amount'                  => $snapshot['base_amount'],
                'discount_percentage'          => 0,
                'discount_amount'              => 0,
                'final_amount'                 => $snapshot['base_amount'],
                'amount_pending_from_previous' => $pendingFromPrevious,
                'snapshot_name'                => $snapshot['snapshot_name'],
                'snapshot_generation'          => $snapshot['snapshot_generation'],
                'snapshot_campus'              => $snapshot['snapshot_campus'],
                'snapshot_scholarship_type'    => $snapshot['snapshot_scholarship_type'],
            ]);

            // Aplicar descuento académico vigente si corresponde
            $this->calculationService->applyAcademicDiscount($refrend, $profile);

            // Evaluar penalización por 2 retardos acumulados en el semestre
            $user = $profile->user;
            $this->penaltyService->applyPenaltyIfDue($refrend, $user, 25.0, $referenceDate);

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
