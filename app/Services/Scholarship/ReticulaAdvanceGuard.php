<?php

namespace App\Services\Scholarship;

use App\Exceptions\ReticulaAdvanceConflictException;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;

/**
 * sdd/revertir-pago-adelantado, S8 (design A3, spec R22).
 *
 * A retícula edit moves the egreso month (end + 2, ScholarshipProfile::
 * egresoMonthStart). An advance month on or after that month would make the
 * automatic egreso generation skip its period (the period is already occupied
 * by the advance refrend). Only PENDING months count: REACHED/OVERRIDDEN are
 * history already settled and CANCELLED ones are void.
 *
 * Must run inside the transaction that holds the profile lock.
 */
class ReticulaAdvanceGuard
{
    /**
     * @throws ReticulaAdvanceConflictException
     */
    public function assertCompatible(ScholarshipProfile $profile, \DateTimeInterface|string $newReticulaEnd): void
    {
        $egreso    = ScholarshipProfile::egresoMonthStart($newReticulaEnd);
        $egresoAbs = $egreso->year * 12 + $egreso->month;

        $conflicts = ScholarshipAdvancePaymentMonth::query()
            ->where('user_id', $profile->user_id)
            ->where('status', ScholarshipAdvancePaymentMonth::STATUS_PENDING)
            ->whereRaw('(period_year * 12 + period_month) >= ?', [$egresoAbs])
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get(['period_year', 'period_month'])
            ->map(fn ($m) => [
                'period_year'  => (int) $m->period_year,
                'period_month' => (int) $m->period_month,
            ])
            ->values()
            ->all();

        if ($conflicts !== []) {
            throw new ReticulaAdvanceConflictException($egreso->year, $egreso->month, $conflicts);
        }
    }
}
