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
        if (!in_array($refrend->workflow_status, ['DRAFT', 'CON_INCIDENCIA'])) {
            throw new \DomainException('Solo se pueden registrar incidencias en refrendos DRAFT o CON_INCIDENCIA.');
        }

        $old           = $this->logging->snapshotRefrend($refrend);
        $existingIncident = $refrend->incidents()->where('is_resolved', false)->first();

        return DB::transaction(function () use ($refrend, $incidentData, $comment, $userId, $old, $existingIncident) {
            if ($existingIncident) {
                $existingIncident->update([
                    'incident_category' => $incidentData['incident_category'],
                    'incident_type'     => $incidentData['incident_type'],
                    'description'       => $incidentData['description'],
                    'priority'          => $incidentData['priority'],
                    'incident_date'     => $incidentData['incident_date'] ?? null,
                ]);
                $logEvent = 'ATENCION_INCIDENT_UPDATED';
            } else {
                $refrend->incidents()->create([
                    'incident_category' => $incidentData['incident_category'],
                    'incident_type'     => $incidentData['incident_type'],
                    'description'       => $incidentData['description'],
                    'priority'          => $incidentData['priority'],
                    'incident_date'     => $incidentData['incident_date'] ?? null,
                    'created_by_id'     => $userId,
                ]);
                $logEvent = 'ATENCION_FLAGGED';
            }

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
                $logEvent,
                $old,
                $this->logging->snapshotRefrend($refrend->fresh()),
                $incidentData['incident_type']
            );

            return $refrend->fresh();
        });
    }
}
