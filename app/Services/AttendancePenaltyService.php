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
            'description'            => 'Suspensión del mes por 2 retardos semestrales acumulados sin justificar.',
        ]);

        foreach ($toConsume as $attendance) {
            ScholarshipLateConsumption::create([
                'scholarship_refrend_discount_id' => $discount->id,
                'attendance_id'                   => $attendance->id,
                'created_at'                      => now(),
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
        $start = Carbon::create($year, $month, 1)->toDateString();
        $today = Carbon::today()->toDateString();

        return Attendance::with('class')
            ->where('user_id', $user->id)
            ->where('status', 'ABSENT')
            ->whereHas('class', fn($q) => $q
                ->where('date', '>=', $start)
                ->where('date', '<=', $today)
            )
            ->get();
    }

    /**
     * Aplica suspensión 100% si el becario tuvo al menos una falta injustificada
     * en el mes del refrendo. Retorna el discount creado o null si no aplica.
     */
    public function applyAbsencePenaltyIfDue(
        ScholarshipRefrend $refrend,
        User $user,
        int $year,
        int $month
    ): ?ScholarshipRefrendDiscount {
        $absences = $this->getUnjustifiedAbsencesInPeriod($user, $year, $month);

        if ($absences->isEmpty()) {
            return null;
        }

        return ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => DiscountType::FALTA_INJUSTIFICADA->value,
            'discount_percentage'    => 100.0,
            'description'            => 'Suspensión del mes por falta injustificada en el periodo.',
        ]);
    }

    /**
     * Neutralizes (zeroes out) active RETARDOS/FALTA_INJUSTIFICADA discount
     * rows for a refrend instead of deleting them. Deleting cascades to
     * scholarship_late_consumptions (onDelete('cascade')), which would
     * un-consume the underlying lates and let the same RETARDOS penalty
     * re-fire the following month. Zeroing the percentage neutralizes the
     * amount while keeping the row (and its consumptions) intact.
     *
     * Iterates + saves rather than a mass update with a raw SQL CONCAT,
     * because tests run against SQLite in-memory and MySQL-only functions
     * break there (see RecalculateRefrendService's CURDATE() fix).
     *
     * Returns the number of rows neutralized.
     */
    public function neutralizeAttendancePenalties(ScholarshipRefrend $refrend): int
    {
        $discounts = ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
            ->whereIn('discount_type', [
                DiscountType::RETARDOS->value,
                DiscountType::FALTA_INJUSTIFICADA->value,
            ])
            ->where('discount_percentage', '>', 0)
            ->get();

        foreach ($discounts as $discount) {
            $discount->discount_percentage = 0;
            $discount->description = trim(($discount->description ?? '') . ' Condonado.');
            $discount->save();
        }

        return $discounts->count();
    }
}
