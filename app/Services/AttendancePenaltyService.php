<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Models\ScholarshipLateConsumption;
use App\Models\User;
use App\Enums\DiscountType;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendancePenaltyService
{
    private const SEMESTER_ONE_START = 1;  // Enero
    private const SEMESTER_ONE_END   = 7;  // Julio
    private const SEMESTER_TWO_START = 8;  // Agosto
    private const SEMESTER_TWO_END   = 12; // Diciembre
    private const LATES_FOR_PENALTY  = 2;

    /**
     * Retorna el rango de fechas del semestre actual.
     * Semestre 1: Enero – Julio
     * Semestre 2: Agosto – Diciembre
     */
    public function getCurrentSemesterBounds(?Carbon $referenceDate = null): array
    {
        $date = $referenceDate ?? Carbon::now();
        $year  = $date->year;
        $month = $date->month;

        if ($month >= self::SEMESTER_TWO_START) {
            return [
                'start' => Carbon::create($year, self::SEMESTER_TWO_START, 1)->startOfDay()->toDateString(),
                'end'   => Carbon::create($year, self::SEMESTER_TWO_END, 31)->endOfDay()->toDateString(),
            ];
        }

        return [
            'start' => Carbon::create($year, self::SEMESTER_ONE_START, 1)->startOfDay()->toDateString(),
            'end'   => Carbon::create($year, self::SEMESTER_ONE_END, 31)->endOfDay()->toDateString(),
        ];
    }

    /**
     * Retorna los retardos sin justificar y sin consumir del becario dentro del semestre actual.
     * Usa classes.date como referencia temporal (no attendances.created_at).
     */
    public function getUnconsumedLatesForUser(User $user, ?Carbon $referenceDate = null): Collection
    {
        ['start' => $start, 'end' => $end] = $this->getCurrentSemesterBounds($referenceDate);

        return Attendance::with('class')
            ->where('user_id', $user->id)
            ->unconsumedLatesInRange($start, $end)
            ->orderBy('id')
            ->get();
    }

    /**
     * Cuenta los retardos sin consumir del becario en el semestre actual.
     */
    public function countUnconsumedLates(User $user, ?Carbon $referenceDate = null): int
    {
        return $this->getUnconsumedLatesForUser($user, $referenceDate)->count();
    }

    /**
     * Evalúa si hay suficientes retardos para aplicar descuento.
     * Si hay 2 o más: aplica el descuento, marca los primeros 2 como consumidos.
     * Retorna el discount creado o null si no había suficientes retardos.
     */
    public function applyPenaltyIfDue(
        ScholarshipRefrend $refrend,
        User $user,
        float $penaltyPercentage = 25.0,
        ?Carbon $referenceDate = null
    ): ?ScholarshipRefrendDiscount {
        $unconsumedLates = $this->getUnconsumedLatesForUser($user, $referenceDate);

        if ($unconsumedLates->count() < self::LATES_FOR_PENALTY) {
            return null;
        }

        $toConsume = $unconsumedLates->take(self::LATES_FOR_PENALTY);

        $discount = ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => DiscountType::RETARDOS->value,
            'discount_percentage'    => $penaltyPercentage,
            'description'            => '2 retardos acumulados sin justificar en el semestre actual.',
        ]);

        foreach ($toConsume as $attendance) {
            ScholarshipLateConsumption::create([
                'scholarship_refrend_discount_id' => $discount->id,
                'attendance_id'                   => $attendance->id,
                'created_at'                      => now(),
            ]);

            $attendance->update([
                'late_penalty_consumed'              => true,
                'late_penalty_consumed_refrend_id'   => $refrend->id,
            ]);
        }

        return $discount;
    }

    /**
     * Detecta faltas injustificadas del becario en el periodo mensual indicado.
     */
    public function getUnjustifiedAbsencesInPeriod(
        User $user,
        int $year,
        int $month
    ): Collection {
        $start = Carbon::create($year, $month, 1)->startOfDay()->toDateString();
        $end   = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return Attendance::with('class')
            ->where('user_id', $user->id)
            ->where('status', 'ABSENT')
            ->whereHas('class', fn($q) => $q->whereBetween('date', [$start, $end]))
            ->get();
    }
}
