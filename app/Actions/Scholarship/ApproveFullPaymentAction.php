<?php

namespace App\Actions\Scholarship;

use App\Enums\DiscountType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Services\ScholarshipCalculationService;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ApproveFullPaymentAction
{
    public function __construct(
        private ScholarshipCalculationService $calculator,
        private ScholarshipLoggingService $logging
    ) {}

    /**
     * Removes all automatic discounts, forces final_amount = base_amount,
     * then advances to LISTO_PARA_PAGO with resolution_type BECA_MES.
     * Use when the encargado wants to override auto-penalties and pay in full.
     */
    public function execute(ScholarshipRefrend $refrend, int $userId): ScholarshipRefrend
    {
        if (!in_array($refrend->workflow_status, ['DRAFT', 'CON_INCIDENCIA'])) {
            throw new \DomainException('Solo se puede aplicar "Pago al 100%" en estado DRAFT o CON_INCIDENCIA.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $userId, $old) {
            // 1. Delete all automatic discounts (cascade removes late consumptions).
            ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
                ->whereIn('discount_type', [
                    DiscountType::RETARDOS->value,
                    DiscountType::FALTA_INJUSTIFICADA->value,
                    DiscountType::PROMEDIO_BAJO->value,
                ])
                ->delete();

            // 2. Recalculate (final_amount = base_amount with no discounts left).
            $fresh = $this->calculator->recalculate($refrend->fresh());

            // 3. Advance to LISTO_PARA_PAGO.
            $fresh->update([
                'workflow_status'         => 'LISTO_PARA_PAGO',
                'resolution_type'         => 'BECA_MES',
                'atencion_reviewed_by_id' => $userId,
                'atencion_reviewed_at'    => now(),
            ]);

            $fresh = $fresh->fresh();
            $this->logging->log($refrend, 'APPROVED_FULL_PAYMENT', $old, $this->logging->snapshotRefrend($fresh));
            return $fresh;
        });
    }
}
