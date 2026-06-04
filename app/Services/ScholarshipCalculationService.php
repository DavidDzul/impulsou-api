<?php

namespace App\Services;

use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Enums\DiscountType;
use App\Enums\RefrendType;

class ScholarshipCalculationService
{
    /**
     * Calcula el monto final del refrendo considerando todos sus descuentos.
     * El monto base siempre viene del snapshot congelado.
     */
    public function calculateFinalAmount(ScholarshipRefrend $refrend): array
    {
        $base = (float) $refrend->base_amount;

        $discounts = ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)->get();

        $totalDiscountPercentage = 0.0;

        foreach ($discounts as $discount) {
            if ($discount->discount_percentage !== null) {
                $totalDiscountPercentage += (float) $discount->discount_percentage;
            }
        }

        // Máximo 100% de descuento (retención total)
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
     * Si el perfil tiene active_discount_percentage y discount_valid_until >= hoy, aplica.
     */
    public function applyAcademicDiscount(
        ScholarshipRefrend $refrend,
        ScholarshipProfile $profile
    ): ?ScholarshipRefrendDiscount {
        if ($profile->active_discount_percentage === null) {
            return null;
        }

        if ($profile->discount_valid_until !== null && $profile->discount_valid_until->isPast()) {
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
     */
    public function buildSnapshot(ScholarshipProfile $profile): array
    {
        $user       = $profile->user()->with('roles')->first();
        $generation = $user->generation_id
            ? \App\Models\Generation::find($user->generation_id)
            : null;

        $monthlyAmount = (float) $profile->monthly_amount;
        $discountPct   = $profile->active_discount_percentage !== null
            ? (float) $profile->active_discount_percentage
            : 0.0;

        $discountActive = $discountPct > 0
            && ($profile->discount_valid_until === null || ! $profile->discount_valid_until->isPast());

        $baseAmount = $discountActive
            ? round($monthlyAmount * (1 - $discountPct / 100), 2)
            : $monthlyAmount;

        return [
            'snapshot_name'                => trim("{$user->first_name} {$user->last_name}"),
            'snapshot_generation'          => $generation?->generation_name,
            'snapshot_generation_id'       => $generation?->id,
            'snapshot_campus'              => $user->campus,
            'snapshot_scholarship_type'    => $profile->scholarship_type->value,
            'base_amount'                  => $baseAmount,
            'snapshot_discount_percentage' => $discountActive ? $discountPct : null,
            'snapshot_discount_reason'     => $discountActive ? $profile->discount_reason : null,
        ];
    }
}
