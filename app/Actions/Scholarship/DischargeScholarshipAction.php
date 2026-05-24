<?php

namespace App\Actions\Scholarship;

use App\Enums\RefrendStatus;
use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class DischargeScholarshipAction
{
    public function __construct(private ScholarshipLoggingService $logging) {}

    public function execute(ScholarshipRefrend $refrend, string $reason, int $userId): ScholarshipRefrend
    {
        if ($refrend->isLocked()) {
            throw new \DomainException('El refrendo ya está cerrado y no puede darse de baja.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $reason, $userId, $old) {
            $refrend->update([
                'status'           => RefrendStatus::CANCELLED->value,
                'workflow_status'  => 'CLOSED',
                'resolution_type'  => 'BAJA',
                'resolution_notes' => $reason,
                'locked_at'        => now(),
                'locked_by_id'     => $userId,
            ]);

            $this->logging->log(
                $refrend,
                'DISCHARGED',
                $old,
                $this->logging->snapshotRefrend($refrend->fresh()),
                $reason
            );

            return $refrend->fresh();
        });
    }
}
