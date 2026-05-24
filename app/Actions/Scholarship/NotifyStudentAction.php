<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class NotifyStudentAction
{
    public function __construct(private ScholarshipLoggingService $logging) {}

    public function execute(ScholarshipRefrend $refrend, ?string $method, int $userId): ScholarshipRefrend
    {
        if ($refrend->workflow_status !== 'PENDIENTE_NOTIFICACION') {
            throw new \DomainException('Solo se pueden notificar refrendos en estado PENDIENTE_NOTIFICACION.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $method, $userId, $old) {
            $refrend->update([
                'workflow_status'     => 'LISTO_PARA_PAGO',
                'notified_by_id'      => $userId,
                'notified_at'         => now(),
                'notification_method' => $method,
            ]);

            $this->logging->log(
                $refrend,
                'STUDENT_NOTIFIED',
                $old,
                $this->logging->snapshotRefrend($refrend->fresh()),
                $method
            );

            return $refrend->fresh();
        });
    }
}
