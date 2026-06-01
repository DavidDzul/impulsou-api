<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ApproveAsIsAction
{
    public function __construct(private ScholarshipLoggingService $logging) {}

    /**
     * Advances the refrend to LISTO_PARA_PAGO without modifying any amounts.
     * resolution_type is auto-detected from the current final_amount.
     */
    public function execute(ScholarshipRefrend $refrend, int $userId): ScholarshipRefrend
    {
        if (!in_array($refrend->workflow_status, ['DRAFT', 'CON_INCIDENCIA'])) {
            throw new \DomainException('Solo se puede aprobar en estado DRAFT o CON_INCIDENCIA.');
        }

        $base  = (float) $refrend->base_amount;
        $final = (float) $refrend->final_amount;

        $resolution = match (true) {
            $final >= $base  => 'BECA_MES',
            $final <= 0      => 'SIN_PAGO',
            default          => 'SUSPENDIDA',
        };

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $userId, $resolution, $old) {
            $refrend->update([
                'workflow_status'         => 'LISTO_PARA_PAGO',
                'resolution_type'         => $resolution,
                'atencion_reviewed_by_id' => $userId,
                'atencion_reviewed_at'    => now(),
            ]);

            $fresh = $refrend->fresh();
            $this->logging->log($refrend, 'APPROVED_AS_IS', $old, $this->logging->snapshotRefrend($fresh));
            return $fresh;
        });
    }
}
