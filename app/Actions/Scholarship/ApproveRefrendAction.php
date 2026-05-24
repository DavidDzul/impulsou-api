<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ApproveRefrendAction
{
    public function __construct(private ScholarshipLoggingService $logging) {}

    public function execute(ScholarshipRefrend $refrend, int $userId): ScholarshipRefrend
    {
        if ($refrend->workflow_status !== 'DRAFT') {
            throw new \DomainException('Solo se pueden aprobar refrendos en estado DRAFT.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $userId, $old) {
            $refrend->update([
                'workflow_status'         => 'LISTO_PARA_PAGO',
                'atencion_reviewed_by_id' => $userId,
                'atencion_reviewed_at'    => now(),
            ]);

            $this->logging->log(
                $refrend,
                'ATENCION_APPROVED',
                $old,
                $this->logging->snapshotRefrend($refrend->fresh())
            );

            return $refrend->fresh();
        });
    }
}
