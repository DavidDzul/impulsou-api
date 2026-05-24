<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ClearRefrendIncidentAction
{
    public function __construct(private ScholarshipLoggingService $logging) {}

    public function execute(ScholarshipRefrend $refrend, int $userId): ScholarshipRefrend
    {
        if ($refrend->workflow_status !== 'CON_INCIDENCIA') {
            throw new \DomainException('Solo se puede limpiar la incidencia en refrendos CON_INCIDENCIA.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $userId, $old) {
            $refrend->incidents()->where('is_resolved', false)->delete();

            $refrend->update([
                'workflow_status'         => 'DRAFT',
                'atencion_observations'   => null,
                'atencion_reviewed_by_id' => $userId,
                'atencion_reviewed_at'    => now(),
            ]);

            $this->logging->log(
                $refrend,
                'ATENCION_INCIDENT_CLEARED',
                $old,
                $this->logging->snapshotRefrend($refrend->fresh())
            );

            return $refrend->fresh();
        });
    }
}
