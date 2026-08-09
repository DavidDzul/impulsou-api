<?php

namespace App\Actions\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Services\Scholarship\RefrendPaymentTotalsSyncer;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

/**
 * Reverts a single withholding-payment child row without affecting any of
 * the retention's other abonos. paid_amount is recomputed (never
 * decremented directly) so a PAID retention correctly reopens to PENDING
 * when its only settling payment is reverted.
 */
class VoidWithholdingPaymentAction
{
    public function __construct(
        private ScholarshipLoggingService $logging,
        private RefrendPaymentTotalsSyncer $totalsSyncer
    ) {}

    public function execute(
        ScholarshipWithholdingPayment $payment,
        string $reason,
        int $userId
    ): ScholarshipWithholdingPayment {
        return DB::transaction(function () use ($payment, $reason, $userId) {
            // Lock order: abono -> retención -> refrendo, identical in spirit to the
            // application path's re-classification guard (RecordPaymentSituationAction) —
            // the already-voided check must be re-read under lock INSIDE the
            // transaction, or two concurrent void requests on the same payment could
            // both pass the check before either one writes (TOCTOU).
            $payment     = ScholarshipWithholdingPayment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->is_voided) {
                throw new \DomainException('Este abono ya fue revertido.');
            }

            $withholding = ScholarshipWithholding::lockForUpdate()->findOrFail($payment->withholding_id);
            $refrend     = ScholarshipRefrend::lockForUpdate()->findOrFail($payment->applied_refrend_id);

            if ($withholding->status === 'CANCELLED') {
                throw new \DomainException('La retención fue cancelada; no admite movimientos.');
            }

            if ($refrend->isLocked()) {
                throw new \DomainException(
                    'El refrendo donde se aplicó este abono ya está cerrado. Use un ajuste (CREDIT/DEBIT) para corregirlo.'
                );
            }

            $old = $this->logging->snapshotRefrend($refrend);

            $payment->update([
                'is_voided'    => true,
                'voided_at'    => now(),
                'voided_by_id' => $userId,
                'void_reason'  => $reason,
            ]);

            $withholding->recomputePaidAmount();
            $this->totalsSyncer->sync($refrend);

            $this->logging->log(
                $refrend,
                'WITHHOLDING_PAYMENT_VOIDED',
                $old,
                $this->logging->snapshotRefrend($refrend->fresh()),
                $reason
            );

            return $payment->fresh();
        });
    }
}
