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
        $settledMonth = ScholarshipAdvancePaymentMonth::with('advancePayment.cancelledCutRefrend')
            ->where('refrend_id', $refrend->id)
            ->first();

        $registeredBatch = ScholarshipAdvancePayment::with('cancelledCutRefrend')
            ->where('origin_refrend_id', $refrend->id)
            ->first();

        $settledHeader = $settledMonth?->advancePayment;

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

            // Cycle-cut cancellation of the header that covers this month
            // (sdd/revertir-pago-adelantado S7). settled_status above is
            // CANCELLED for a cancelled month.
            'settled_header_status'         => $settledHeader?->status,
            'settled_cancelled_at'          => $settledHeader?->cancelled_at?->toIso8601String(),
            'settled_cancel_reason'         => $settledHeader?->cancel_reason,
            'settled_cancelled_cut_refrend' => $this->cutRefrend($settledHeader),

            // This refrend itself IS the origin of a batch registered
            // against it (staff advanced future months FROM here).
            'has_registered_batch'    => $registeredBatch !== null,
            'registered_months_count' => $registeredBatch?->months_count,
            'registered_total_amount' => $registeredBatch?->total_amount,
            'registered_cause'        => $registeredBatch?->cause,
            'registered_notes'        => $registeredBatch?->notes,

            // Cycle-cut cancellation of the batch registered FROM this
            // refrend (sdd/revertir-pago-adelantado S7). Amounts are
            // 2-decimal strings; null when there is no batch.
            'registered_status'              => $registeredBatch?->status,
            'registered_cancelled_at'        => $registeredBatch?->cancelled_at?->toIso8601String(),
            'registered_cancel_reason'       => $registeredBatch?->cancel_reason,
            'registered_cancelled_cut_refrend' => $this->cutRefrend($registeredBatch),
            'registered_cancelled_amount'    => $this->money($registeredBatch?->cancelled_amount),
            'registered_overpaid_amount'     => $this->money($registeredBatch?->overpaid_amount),
        ];
    }

    /**
     * The refrend whose baja/egreso cut cancelled the header, as
     * {id, period_year, period_month}; null while the header is not cancelled.
     *
     * @return array{id: int, period_year: int, period_month: int}|null
     */
    private function cutRefrend(?ScholarshipAdvancePayment $header): ?array
    {
        $cut = $header?->cancelledCutRefrend;

        return $cut === null ? null : [
            'id'           => (int) $cut->id,
            'period_year'  => (int) $cut->period_year,
            'period_month' => (int) $cut->period_month,
        ];
    }

    private function money(mixed $amount): ?string
    {
        return $amount === null ? null : number_format((float) $amount, 2, '.', '');
    }
}
