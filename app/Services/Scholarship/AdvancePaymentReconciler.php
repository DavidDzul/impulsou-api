<?php

namespace App\Services\Scholarship;

use App\Exceptions\AdvanceDivergenceRequiredException;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;

/**
 * Advance-payment arrival reconciliation (design D4), extracted 2026-09-27
 * so it can be shared by EVERY action that can settle a refrend's
 * final_amount for the month — not just RecordPaymentSituationAction's 8
 * resolution_type branches. Originally lived inline only in that action;
 * ApproveFullPaymentAction (the quick "Aprobar" menu item) settled
 * advance-paid arrivals completely unchecked — no divergence check, no
 * required reason, no update to the advance ledger's status — a real gap
 * found via live testing (staff's most natural way to approve at 100% never
 * went through the reconciliation logic at all).
 *
 * If $refrend has no linked scholarship_advance_payment_months row (i.e. it
 * was never pre-created by RecordAdvancePaymentAction), this is a complete
 * no-op — one indexed lookup, no writes.
 *
 * Divergence = staff is paying something THIS month (final_amount > 0) on
 * top of what the becario already received via the advance — the real
 * double-payment risk, and the case that needs an explanation. A
 * final_amount = 0 outcome (SIN_PAGO, BAJA_DEFINITIVA, a 100%
 * RETENIDA/SUSPENDIDA) is the safe, expected outcome and never requires a
 * reason (corrected 2026-09-25 — the original check compared against the
 * advance-paid amount and had this backwards).
 */
class AdvancePaymentReconciler
{
    /**
     * MUST be called from inside the SAME DB transaction as the caller's own
     * final_amount mutation, so a diverging resolution without a reason
     * rolls back everything the caller already did too. $refrend must
     * already reflect the settled final_amount (post-update / post-fresh())
     * when this is called.
     *
     * @throws AdvanceDivergenceRequiredException
     */
    public function reconcile(ScholarshipRefrend $refrend, string $settledResolutionType, ?string $divergenceReason): void
    {
        $advanceMonth = ScholarshipAdvancePaymentMonth::where('refrend_id', $refrend->id)
            ->lockForUpdate()
            ->first();

        if (!$advanceMonth) {
            return;
        }

        $diverged = (float) $refrend->final_amount > 0.01;
        if ($diverged && empty($divergenceReason)) {
            throw new AdvanceDivergenceRequiredException();
        }

        $advanceMonth->update([
            'status'                  => $diverged ? 'OVERRIDDEN' : 'REACHED',
            'settled_resolution_type' => $settledResolutionType,
            'divergence_reason'       => $divergenceReason,
            'reached_at'              => now(),
        ]);
    }
}
