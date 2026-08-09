<?php

namespace App\Actions\Scholarship;

use App\Enums\RefrendStatus;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Services\RecalculateRefrendService;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class ClearRefrendResolutionAction
{
    public function __construct(
        private ScholarshipLoggingService $logging,
        private RecalculateRefrendService $recalculate
    ) {}

    public function execute(ScholarshipRefrend $refrend, int $userId): ScholarshipRefrend
    {
        // GUARD 1 — lock. isLocked() alone is INSUFFICIENT here: it returns false
        // for LISTO_PARA_PAGO (ScholarshipRefrend::isLocked() short-circuits before
        // reaching its locked_at and legacy-enum branches). Re-apply precisely
        // those two skipped branches so a legacy lock or CLOSED/CANCELLED/PAID
        // still rejects with the *lock* message, not "nothing to undo".
        if ($refrend->isLocked() || $refrend->locked_at !== null || $refrend->status->isLocked()) {
            throw new \DomainException('El refrendo está bloqueado y no admite cambios.');
        }

        // GUARD 2 — precondition: only a resolved refrend can be undone.
        if ($refrend->workflow_status !== 'LISTO_PARA_PAGO') {
            throw new \DomainException('Solo se puede deshacer la resolución de refrendos en estado LISTO_PARA_PAGO.');
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $userId, $old) {
            // GUARD 3 — retention already paid. Locked inside the same
            // transaction as the mutation below to close the check-then-act
            // window, mirroring RecordPaymentSituationAction:132-137.
            $existingLedger = ScholarshipWithholding::where('origin_refrend_id', $refrend->id)
                ->lockForUpdate()
                ->first();
            if ($existingLedger && (float) $existingLedger->paid_amount > 0) {
                throw new \DomainException('No se puede modificar una retención que ya tiene pagos aplicados.');
            }

            // GUARD 4 — active abonos already applied on this refrend. Runs
            // unconditionally (unlike RecordPaymentSituationAction, which only
            // checks it when $paymentInputs is non-empty) because undo always
            // invalidates this refrend's abonos.
            if (
                ScholarshipWithholdingPayment::where('applied_refrend_id', $refrend->id)
                    ->where('is_voided', false)
                    ->exists()
            ) {
                throw new \DomainException(
                    'Este refrendo ya tiene abonos aplicados. Revierta los abonos antes de deshacer la resolución.'
                );
            }

            // Cancel the pending retention ledger row, if any. Guard 3 proves
            // paid_amount = 0, so nothing is orphaned. Cancel, never delete.
            if ($existingLedger && $existingLedger->status !== 'CANCELLED') {
                $existingLedger->update(['status' => 'CANCELLED']);
            }

            $refrend->update([
                'workflow_status'             => 'DRAFT',
                'status'                      => RefrendStatus::DRAFT->value,
                'resolution_type'             => null,
                'resolution_cause'            => null,
                'resolution_notes'            => null,
                'suspension_percentage'       => null,
                'withholding_mode'            => null,
                'withholding_value'           => null,
                'refund_amount_from_previous' => '0.00',
                'notified_at'                 => null,
                'notified_by_id'              => null,
                'notification_method'         => null,
                'atencion_reviewed_by_id'     => $userId,
                'atencion_reviewed_at'        => now(),
            ]);

            // fullRecalculate() re-derives snapshots, attendance penalties, and
            // the final DRAFT vs. CON_INCIDENCIA workflow_status from live data.
            // It already returns the recalculated instance — do not fetch again.
            $fresh = $this->recalculate->fullRecalculate($refrend);

            $this->logging->log(
                $refrend,
                'RESOLUTION_CLEARED',
                $old,
                $this->logging->snapshotRefrend($fresh)
            );

            return $fresh;
        });
    }
}
