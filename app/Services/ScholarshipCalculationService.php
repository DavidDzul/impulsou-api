<?php

namespace App\Services;

use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Enums\DiscountType;
use App\Enums\RefrendType;
use Carbon\Carbon;

class ScholarshipCalculationService
{
    /**
     * Calcula el monto final del refrendo considerando todos sus descuentos.
     * El monto base siempre viene del snapshot congelado.
     */
    public function calculateFinalAmount(ScholarshipRefrend $refrend): array
    {
        $gross       = (float) $refrend->snapshot_gross_amount;
        $academicPct = (float) ($refrend->snapshot_discount_percentage ?? 0);
        $base        = round($gross * (1 - $academicPct / 100), 2);

        $discounts = ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)->get();

        $totalDiscountPercentage = 0.0;
        foreach ($discounts as $discount) {
            if ($discount->discount_percentage !== null) {
                $totalDiscountPercentage += (float) $discount->discount_percentage;
            }
        }

        $totalDiscountPercentage = min($totalDiscountPercentage, 100.0);

        $discountAmount = round($base * ($totalDiscountPercentage / 100), 2);
        $finalAmount    = round($base - $discountAmount, 2);

        return [
            'discount_percentage' => $totalDiscountPercentage,
            'discount_amount'     => $discountAmount,
            'final_amount'        => max(0, $finalAmount),
        ];
    }

    /**
     * Actualiza los campos calculados del refrendo y lo guarda.
     */
    public function recalculate(ScholarshipRefrend $refrend): ScholarshipRefrend
    {
        $result = $this->calculateFinalAmount($refrend);

        $refrend->update([
            'discount_percentage' => $result['discount_percentage'],
            'discount_amount'     => $result['discount_amount'],
            'final_amount'        => $result['final_amount'],
        ]);

        return $refrend->fresh();
    }

    /**
     * Aplica el descuento por promedio académico vigente del perfil del becario.
     * Es una retención TEMPORAL: requiere active_discount_percentage y una
     * discount_valid_until presente y no vencida. Sin fecha registrada se
     * trata como dato inválido/inactivo, nunca como "sin vencimiento".
     */
    public function applyAcademicDiscount(
        ScholarshipRefrend $refrend,
        ScholarshipProfile $profile
    ): ?ScholarshipRefrendDiscount {
        if ($profile->active_discount_percentage === null) {
            return null;
        }

        if (! $profile->isDiscountActiveOn()) {
            return null;
        }

        $baseDesc    = 'Descuento por promedio académico vigente.';
        $description = $profile->discount_reason
            ? $baseDesc . ' Motivo: ' . $profile->discount_reason
            : $baseDesc;

        return ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => DiscountType::PROMEDIO_BAJO->value,
            'discount_percentage'    => $profile->active_discount_percentage,
            'description'            => $description,
        ]);
    }

    /**
     * Aplica retención total (100%) al refrendo.
     */
    public function applyRetention(
        ScholarshipRefrend $refrend,
        string $reason = 'Retención de beca.'
    ): ScholarshipRefrendDiscount {
        return ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => DiscountType::FALTA_INJUSTIFICADA->value,
            'discount_percentage'    => 100.0,
            'description'            => $reason,
        ]);
    }

    /**
     * Construye el snapshot de datos históricos del becario al momento de generar el refrendo.
     * Si el perfil tiene un descuento base vigente, lo aplica directamente sobre monthly_amount
     * para que los descuentos mensuales posteriores operen sobre el monto ya reducido.
     *
     * El aumento temporal vigente (monto fijo, evaluado por fecha exacta, sin
     * prorrateo) se suma DENTRO de snapshot_gross_amount, igual que
     * monto_apoyo, por lo que queda sujeto al descuento académico (no exento).
     * base_amount NO incluye el aumento (bug preexistente de la variable
     * muerta, fuera de scope de este cambio).
     */
    public function buildSnapshot(ScholarshipProfile $profile, ?Carbon $on = null): array
    {
        $on = ($on ?? Carbon::today())->copy()->startOfDay();

        $user       = $profile->user()->with('roles')->first();
        $generation = $user->generation_id
            ? \App\Models\Generation::find($user->generation_id)
            : null;

        $monthlyAmount = (float) $profile->monthly_amount;
        $montoApoyo    = (float) ($profile->monto_apoyo ?? 0);

        $increaseActive = $profile->isTemporaryIncreaseActiveOn($on);
        $increaseAmount = $increaseActive ? (float) $profile->temporary_increase_amount : 0.0;

        $totalMonthly = $monthlyAmount + $montoApoyo + $increaseAmount;

        $discountPct    = $profile->active_discount_percentage !== null
            ? (float) $profile->active_discount_percentage
            : 0.0;
        $discountActive = $discountPct > 0 && $profile->isDiscountActiveOn($on);

        $baseAmount = $discountActive
            ? round($totalMonthly * (1 - $discountPct / 100), 2)
            : $totalMonthly;

        return [
            'snapshot_name'                       => trim("{$user->first_name} {$user->last_name}"),
            'snapshot_generation'                 => $generation?->generation_name,
            'snapshot_generation_id'               => $generation?->id,
            'snapshot_campus'                     => $user->campus,
            'snapshot_scholarship_type'           => $profile->scholarship_type->value,
            'snapshot_gross_amount'               => $totalMonthly,
            'snapshot_monto_apoyo'                => $montoApoyo,
            'base_amount'                         => $monthlyAmount,
            'snapshot_discount_percentage'        => $discountActive ? $discountPct : null,
            'snapshot_discount_reason'             => $discountActive ? $profile->discount_reason : null,
            'snapshot_temporary_increase_amount'  => $increaseActive ? $increaseAmount : null,
            'snapshot_temporary_increase_reason'  => $increaseActive ? $profile->temporary_increase_reason : null,
        ];
    }
}
