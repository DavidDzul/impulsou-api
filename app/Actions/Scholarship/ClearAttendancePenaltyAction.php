<?php

namespace App\Actions\Scholarship;

use App\Enums\DiscountType;
use App\Models\Attendance;
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
     * resets consumed-late flags on the related attendances,
     * sets attendance_penalty_override so recalculate won't re-apply them,
     * and recalculates the final amount.
     */
    public function execute(ScholarshipRefrend $refrend, int $userId): ScholarshipRefrend
    {
        if ($refrend->workflow_status !== 'DRAFT') {
            throw new \DomainException('Solo se pueden ajustar penalizaciones en refrendos en estado DRAFT.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $userId, $old) {
            // Un-consume lates linked to RETARDOS discounts of this refrend.
            $retardosDiscounts = ScholarshipRefrendDiscount::where('scholarship_refrend_id', $refrend->id)
                ->where('discount_type', DiscountType::RETARDOS->value)
                ->with('lateConsumptions')
                ->get();

            foreach ($retardosDiscounts as $discount) {
                foreach ($discount->lateConsumptions as $consumption) {
                    Attendance::where('id', $consumption->attendance_id)->update([
                        'late_penalty_consumed'            => false,
                        'late_penalty_consumed_refrend_id' => null,
                    ]);
                }
            }

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
