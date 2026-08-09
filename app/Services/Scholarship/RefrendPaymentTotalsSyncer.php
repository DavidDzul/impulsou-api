<?php

namespace App\Services\Scholarship;

use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholdingPayment;

/**
 * Derives amount_pending_from_previous / carryover_months_count /
 * carryover_months_detail on a refrend from its active (non-voided)
 * withholding-payment children.
 *
 * Shared by RecordPaymentSituationAction (applying a payment) and
 * VoidWithholdingPaymentAction (reverting one) so both leave the refrend in
 * an identically consistent state — extracted out of
 * RecordPaymentSituationAction (where it started as a private method) once
 * PR4 needed the exact same derivation for reversal.
 */
class RefrendPaymentTotalsSyncer
{
    public function sync(ScholarshipRefrend $refrend): void
    {
        $rows = ScholarshipWithholdingPayment::with('withholding')
            ->where('applied_refrend_id', $refrend->id)
            ->where('is_voided', false)
            ->get();

        $refrend->amount_pending_from_previous = number_format(
            round($rows->sum(fn ($p) => (float) $p->amount), 2),
            2,
            '.',
            ''
        );
        $refrend->carryover_months_count  = $rows->count();
        $refrend->carryover_months_detail = $rows->map(fn ($p) => sprintf(
            '%02d/%d: %s',
            $p->withholding->period_month,
            $p->withholding->period_year,
            number_format((float) $p->amount, 2)
        ))->implode('; ');
        $refrend->save();
    }
}
