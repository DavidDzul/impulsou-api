<?php

namespace App\Services;

use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;

class ScholarshipLoggingService
{
    /**
     * Capture a snapshot of the refrend's mutable fields for change-tracking.
     */
    public function snapshotRefrend(ScholarshipRefrend $refrend): array
    {
        return [
            'status'              => $refrend->status,
            'workflow_status'     => $refrend->workflow_status,
            'base_amount'         => $refrend->base_amount,
            'discount_percentage' => $refrend->discount_percentage,
            'discount_amount'     => $refrend->discount_amount,
            'final_amount'        => $refrend->final_amount,
            'total_to_pay'        => $refrend->total_to_pay,
            'atencion_labels'     => $refrend->atencion_labels,
            'atencion_observations'   => $refrend->atencion_observations,
            'pedagogia_observations'  => $refrend->pedagogia_observations,
            'notification_method' => $refrend->notification_method,
            'notified_at'         => $refrend->notified_at,
        ];
    }

    /**
     * Persist a log entry for the given action.
     */
    public function log(
        ScholarshipRefrend $refrend,
        string $action,
        ?array $old = null,
        ?array $new = null,
        ?string $notes = null
    ): void {
        ScholarshipRefrendLog::create([
            'scholarship_refrend_id' => $refrend->id,
            'performed_by_id'        => auth()->id(),
            'action'                 => $action,
            'old_values'             => $old,
            'new_values'             => $new,
            'notes'                  => $notes,
        ]);
    }
}
