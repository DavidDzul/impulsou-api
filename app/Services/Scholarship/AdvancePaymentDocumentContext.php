<?php

namespace App\Services\Scholarship;

use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;

/**
 * READ-side assembler for the payment document's advance-payment context
 * (administration-panel's "Ver" modal, sdd/pago-adelantado, added
 * 2026-09-27). Mirrors RefrendRetentionBreakdown's precedent: consumed by
 * exactly one caller, ScholarshipPaymentController::document(), keeping
 * that controller thin. Pure read, no writes.
 *
 * A refrend can independently be BOTH directions at once — settled as one
 * of its own becario's earlier advance-paid months AND itself have a NEW
 * batch registered against it (staff advancing again from this same
 * refrend) — never conflate the two, they come from different rows
 * (scholarship_advance_payment_months vs scholarship_advance_payments).
 */
class AdvancePaymentDocumentContext
{
    public function forRefrend(ScholarshipRefrend $refrend): array
    {
        $settledMonth = ScholarshipAdvancePaymentMonth::with('advancePayment')
            ->where('refrend_id', $refrend->id)
            ->first();

        $registeredBatch = ScholarshipAdvancePayment::where('origin_refrend_id', $refrend->id)
            ->first();

        return [
            // This refrend IS one of the future months an EARLIER batch
            // (registered from some other, past refrend) settled.
            'settled_as_advance'      => $settledMonth !== null,
            'origin_period_year'      => $settledMonth?->advancePayment?->origin_period_year,
            'origin_period_month'     => $settledMonth?->advancePayment?->origin_period_month,
            'settled_amount'          => $settledMonth?->amount,
            'settled_status'          => $settledMonth?->status,
            'settled_resolution_type' => $settledMonth?->settled_resolution_type,
            'divergence_reason'       => $settledMonth?->divergence_reason,
            'reached_at'              => $settledMonth?->reached_at?->toIso8601String(),

            // This refrend itself IS the origin of a batch registered
            // against it (staff advanced future months FROM here).
            'has_registered_batch'    => $registeredBatch !== null,
            'registered_months_count' => $registeredBatch?->months_count,
            'registered_total_amount' => $registeredBatch?->total_amount,
            'registered_cause'        => $registeredBatch?->cause,
            'registered_notes'        => $registeredBatch?->notes,
        ];
    }
}
