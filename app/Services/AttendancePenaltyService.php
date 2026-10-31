<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Models\ScholarshipLateConsumption;
use App\Models\User;
use App\Enums\DiscountType;
use App\Services\Scholarship\AttendanceWindow;
use Illuminate\Support\Collection;

class AttendancePenaltyService
{
    private const LATES_FOR_PENALTY = 2;

    /**
     * Retorna los retardos sin justificar y sin consumir del becario dentro de la ventana.
     * Usa classes.date como referencia temporal (no attendances.created_at).
     */
    public function getUnconsumedLatesForUser(User $user, AttendanceWindow $window): Collection
    {
        return Attendance::with('class')
            ->where('user_id', $user->id)
            ->unconsumedLatesInRange($window->start, $window->end)
            ->orderBy('id')
            ->get();
    }

    /**
     * Cuenta los retardos sin consumir del becario dentro de la ventana.
     */
    public function countUnconsumedLates(User $user, AttendanceWindow $window): int
    {
        return $this->getUnconsumedLatesForUser($user, $window)->count();
    }

    /**
     * Evalúa si hay suficientes retardos para aplicar descuento.
     * Si hay 2 o más: aplica el descuento, marca los primeros 2 como consumidos.
     * Retorna el discount creado o null si no había suficientes retardos.
     */
    public function applyPenaltyIfDue(
        ScholarshipRefrend $refrend,
        User $user,
        float $penaltyPercentage,
        AttendanceWindow $window
    ): ?ScholarshipRefrendDiscount {
        $unconsumedLates = $this->getUnconsumedLatesForUser($user, $window);

        if ($unconsumedLates->count() < self::LATES_FOR_PENALTY) {
            return null;
        }

        $toConsume = $unconsumedLates->take(self::LATES_FOR_PENALTY);

        $discount = ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => DiscountType::RETARDOS->value,
            'discount_percentage'    => $penaltyPercentage,
            'description'            => 'Suspensión del mes por 2 retardos semestrales acumulados sin justificar.',
            'is_automatic'           => true,
        ]);

        $this->consume($discount, $toConsume);

        return $discount;
    }

    /**
     * Faltas injustificadas (ABSENT) del becario sin consumir dentro de la ventana.
     */
    public function getUnconsumedAbsences(User $user, AttendanceWindow $window): Collection
    {
        return Attendance::with('class')
            ->where('user_id', $user->id)
            ->unconsumedAbsencesInRange($window->start, $window->end)
            ->orderBy('id')
            ->get();
    }

    /**
     * Aplica UNA suspensión 100% si hay al menos una falta injustificada sin
     * consumir en la ventana, y consume TODAS esas faltas con ese descuento.
     * Retorna el discount creado o null si no aplica.
     */
    public function applyAbsencePenaltyIfDue(
        ScholarshipRefrend $refrend,
        User $user,
        AttendanceWindow $window
    ): ?ScholarshipRefrendDiscount {
        $absences = $this->getUnconsumedAbsences($user, $window);

        if ($absences->isEmpty()) {
            return null;
        }

        $discount = ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => DiscountType::FALTA_INJUSTIFICADA->value,
            'discount_percentage'    => 100.0,
            'description'            => 'Suspensión del mes por falta injustificada en el semestre.',
            'is_automatic'           => true,
        ]);

        $this->consume($discount, $absences);

        return $discount;
    }

    private function consume(ScholarshipRefrendDiscount $discount, Collection $attendances): void
    {
        foreach ($attendances as $attendance) {
            ScholarshipLateConsumption::create([
                'scholarship_refrend_discount_id' => $discount->id,
                'attendance_id'                   => $attendance->id,
                'created_at'                      => now(),
            ]);
        }
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
