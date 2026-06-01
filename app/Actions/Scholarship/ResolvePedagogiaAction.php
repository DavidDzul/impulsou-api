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
            throw new \DomainException('Solo se puede agregar comentario de pedagogía en refrendos CON_INCIDENCIA.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $data, $userId, $old) {
            $updates = [
                'pedagogia_reviewed_by_id' => $userId,
                'pedagogia_reviewed_at'    => now(),
            ];

            if (!empty($data['comment'])) {
                $updates['pedagogia_observations'] = $data['comment'];
            }

            $refrend->update($updates);

            $this->logging->log(
                $refrend,
                'PEDAGOGIA_COMMENTED',
                $old,
                $this->logging->snapshotRefrend($refrend->fresh())
            );

            return $refrend->fresh();
        });
    }
}
