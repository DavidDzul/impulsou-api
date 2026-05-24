<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ResolvePedagogiaAction
{
    public function __construct(private ScholarshipLoggingService $logging) {}

    public function execute(ScholarshipRefrend $refrend, array $data, int $userId): ScholarshipRefrend
    {
        if ($refrend->workflow_status !== 'CON_INCIDENCIA') {
            throw new \DomainException('Solo se pueden resolver refrendos en estado CON_INCIDENCIA.');
        }

        $notifyStudent = (bool) $data['notify_student'];
        $old           = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $data, $userId, $notifyStudent, $old) {
            $updates = [
                'workflow_status'          => $notifyStudent ? 'PENDIENTE_NOTIFICACION' : 'LISTO_PARA_PAGO',
                'pedagogia_resolved_by_id' => $userId,
                'pedagogia_resolved_at'    => now(),
                'pedagogia_reviewed_by_id' => $userId,
                'pedagogia_reviewed_at'    => now(),
            ];

            if (!empty($data['comment'])) {
                $updates['pedagogia_observations'] = $data['comment'];
            }

            $refrend->update($updates);

            $this->logging->log(
                $refrend,
                'PEDAGOGIA_RESOLVED',
                $old,
                $this->logging->snapshotRefrend($refrend->fresh()),
                $notifyStudent ? 'notify_required' : 'direct_approval'
            );

            return $refrend->fresh();
        });
    }
}
