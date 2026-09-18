<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ResolveAprobacionAction
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

            // array_key_exists (not !empty()) — the frontend's "remove" flow
            // explicitly sends comment=null to clear an existing comment, and
            // !empty() silently discarded that, leaving the stale comment in
            // place while still reporting success (user-reported). Only skip
            // the field entirely when the caller never mentioned it.
            if (array_key_exists('comment', $data)) {
                $updates['pedagogia_observations'] = ($data['comment'] ?? '') !== ''
                    ? $data['comment']
                    : null;
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
