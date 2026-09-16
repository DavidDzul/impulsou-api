<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Services\AttendancePenaltyService;
use App\Services\ScholarshipCalculationService;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ApproveFullPaymentAction
{
    public function __construct(
        private ScholarshipCalculationService $calculator,
        private ScholarshipLoggingService $logging,
        private AttendancePenaltyService $attendancePenalty
    ) {}

    /**
     * Forgives active RETARDOS/FALTA_INJUSTIFICADA attendance discounts for
     * the refrend (neutralized, not deleted — see
     * AttendancePenaltyService::neutralizeAttendancePenalties), then
     * advances to LISTO_PARA_PAGO. resolution_type is set to BECA_MES only
     * when a penalty was actually forgiven — a becario with nothing to
     * waive ends up in the same state as a plain bulk approval (null
     * resolution_type), since nothing was resolved specially for them.
     * Does NOT touch the academic discount (snapshot_discount_percentage),
     * which remains authoritative through recalculate().
     */
    public function execute(ScholarshipRefrend $refrend, int $userId): ScholarshipRefrend
    {
        // GUARD — lock. Mirrors ClearRefrendResolutionAction:27: isLocked()
        // alone is insufficient (short-circuits to false for LISTO_PARA_PAGO
        // before reaching its locked_at and legacy-enum branches).
        if ($refrend->isLocked() || $refrend->locked_at !== null || $refrend->status->isLocked()) {
            throw new \DomainException('El refrendo está bloqueado y no admite cambios.');
        }

        if (!in_array($refrend->workflow_status, ['DRAFT', 'CON_INCIDENCIA'])) {
            throw new \DomainException('Solo se puede aplicar "Pagar sin descuento por faltas" en estado DRAFT o CON_INCIDENCIA.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $userId, $old) {
            // 1. Neutralize active attendance discounts (RETARDOS/FALTA_INJUSTIFICADA).
            $forgivenCount = $this->attendancePenalty->neutralizeAttendancePenalties($refrend);

            // 2. Recalculate (final_amount reflects the remaining academic discount, if any).
            $fresh = $this->calculator->recalculate($refrend->fresh());

            // 3. Advance to LISTO_PARA_PAGO. Only tag resolution_type when a
            // penalty was actually forgiven (see docblock).
            $fresh->update([
                'workflow_status'         => 'LISTO_PARA_PAGO',
                'resolution_type'         => $forgivenCount > 0 ? 'BECA_MES' : null,
                'atencion_reviewed_by_id' => $userId,
                'atencion_reviewed_at'    => now(),
            ]);

            $fresh = $fresh->fresh();
            $this->logging->log($refrend, 'APPROVED_FULL_PAYMENT', $old, $this->logging->snapshotRefrend($fresh));
            return $fresh;
        });
    }
}
