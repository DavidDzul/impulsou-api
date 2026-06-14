<?php

namespace App\Actions\Scholarship;

use App\Enums\DiscountType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Services\ScholarshipCalculationService;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ClearAttendancePenaltyAction
{
    public function __construct(
        private ScholarshipCalculationService $calculator,
        private ScholarshipLoggingService $logging
    ) {}

    /**
     * Removes all automatic attendance penalties (tardies + absences),
     * sets attendance_penalty_override so recalculate won't re-apply them,
     * and recalculates the final amount.
     * Late consumptions are removed via cascade when discounts are deleted.
     */
    public function execute(ScholarshipRefrend $refrend, int $userId): ScholarshipRefrend
    {
        if ($refrend->workflow_status !== 'DRAFT') {
            throw new \DomainException('Solo se pueden ajustar penalizaciones en refrendos en estado DRAFT.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $userId, $old) {
            // Delete all attendance-based auto discounts (cascade removes late consumptions).
            ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
                ->whereIn('discount_type', [
                    DiscountType::RETARDOS->value,
                    DiscountType::FALTA_INJUSTIFICADA->value,
                ])
                ->delete();

            // Mark override so recalculate won't re-apply them.
            $refrend->update(['attendance_penalty_override' => true]);

            // Re-sum remaining discounts to update final_amount.
            $fresh = $this->calculator->recalculate($refrend->fresh());

            $this->logging->log(
                $fresh,
                'ATTENDANCE_PENALTY_CLEARED',
                $old,
                $this->logging->snapshotRefrend($fresh)
            );

            return $fresh;
        });
    }
}
