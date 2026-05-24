<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class FlagRefrendIncidentAction
{
    public function __construct(private ScholarshipLoggingService $logging) {}

    public function execute(
        ScholarshipRefrend $refrend,
        array $incidentData,
        string $comment,
        int $userId
    ): ScholarshipRefrend {
        if ($refrend->workflow_status !== 'DRAFT') {
            throw new \DomainException('Solo se pueden marcar incidencias en refrendos DRAFT.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $incidentData, $comment, $userId, $old) {
            $refrend->incidents()->create([
                'incident_category' => $incidentData['incident_category'],
                'incident_type'     => $incidentData['incident_type'],
                'description'       => $incidentData['description'],
                'priority'          => $incidentData['priority'],
                'incident_date'     => $incidentData['incident_date'] ?? null,
                'created_by_id'     => $userId,
            ]);

            $updates = [
                'workflow_status'         => 'CON_INCIDENCIA',
                'atencion_reviewed_by_id' => $userId,
                'atencion_reviewed_at'    => now(),
            ];

            if ($comment !== '') {
                $updates['atencion_observations'] = $comment;
            }

            $refrend->update($updates);

            $this->logging->log(
                $refrend,
                'ATENCION_FLAGGED',
                $old,
                $this->logging->snapshotRefrend($refrend->fresh()),
                $incidentData['incident_type']
            );

            return $refrend->fresh();
        });
    }
}
