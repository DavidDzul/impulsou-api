<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * sdd/egresado-status-timing, design D3.
 *
 * Shared by both the manual graduation path (PersonController::graduate(),
 * performed_by_id = auth()->id()) and the automatic retícula month+2 path
 * (GenerateMonthlyRefrendsService, performed_by_id = null — no authenticated
 * user exists for a system-triggered generation run).
 *
 * Deviation from the original proposal (deliberate, design R4): the log row
 * is written DIRECTLY here, mirroring PersonController's pre-extraction
 * inline pattern, instead of adding an optional `?int $performedById`
 * parameter to ScholarshipLoggingService::log(). A `$performedById ??
 * auth()->id()` fallback cannot express "explicitly null" and would
 * mis-attribute the system log to whichever staff member happened to
 * trigger an HTTP-initiated generation run.
 */
class GraduateBecarioAction
{
    public function execute(
        User $person,
        ?ScholarshipRefrend $contextRefrend,
        string $comment,
        ?int $performedById = null
    ): User {
        // Idempotency: a repeated call for someone who already graduated
        // (or who was never BEC_ACTIVE) is a silent no-op. Validation 422s
        // (manual path) stay the controller's responsibility.
        if ($person->user_type !== 'BEC_ACTIVE') {
            return $person;
        }

        return DB::transaction(function () use ($person, $contextRefrend, $comment, $performedById) {
            $person->update(['user_type' => 'BEC_INACTIVE']);

            ScholarshipRefrendLog::create([
                'scholarship_refrend_id' => $contextRefrend?->id
                    ?? ScholarshipRefrend::where('user_id', $person->id)->latest()->value('id'),
                'performed_by_id' => $performedById,
                'action'          => 'graduated',
                'notes'           => $comment,
            ]);

            return $person->fresh();
        });
    }
}
