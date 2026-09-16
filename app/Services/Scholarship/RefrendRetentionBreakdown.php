<?php

namespace App\Services\Scholarship;

use App\Enums\DiscountType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Models\User;

/**
 * READ-side twin of RefrendPaymentTotalsSyncer: assembles the retention
 * "kinds" a single refrend may show on its payment document
 * (sdd/withholding-detail-display, design D1/D2/D3). Consumed by exactly one
 * caller, ScholarshipPaymentController::document() (wired in PR2 — this
 * class ships standalone/unused in PR1). Pure read, no writes.
 *
 * CROSS-REFERENCE (mandatory — design's explicit risk mitigation): the
 * `ledgerApplied()` query below MUST stay filtered by `is_voided = false`
 * ONLY — NEVER add a `status != 'CANCELLED'` filter to it. It is
 * byte-identical, on purpose, to the query in
 * RefrendPaymentTotalsSyncer::sync() (app/Services/Scholarship/RefrendPaymentTotalsSyncer.php:23-26),
 * which derives `amount_pending_from_previous` the same unfiltered-by-status
 * way. Adding a status filter here would desync `ledger_applied_total` from
 * that column and break the reconciliation invariant this class exists to
 * preserve. The CANCELLED exclusion belongs ONLY in `originWithholding()`
 * below (design D2) — it answers a structurally different question ("did
 * THIS refrend originate an active retention?"), not "what did this refrend
 * pay off?".
 */
class RefrendRetentionBreakdown
{
    public function forRefrend(ScholarshipRefrend $refrend): array
    {
        $ledgerApplied = $this->ledgerApplied($refrend);

        return [
            'ledger_applied'       => $ledgerApplied,
            'ledger_applied_total' => $this->sumAppliedNow($ledgerApplied),
            'origin_withholding'   => $this->originWithholding($refrend),
            'attendance_discounts' => $this->attendanceDiscounts($refrend),
            'definitive_discount'  => $this->definitiveDiscount($refrend),
        ];
    }

    /**
     * Kind 1a — money settled by THIS refrend against earlier-period
     * withholdings. See the class-level cross-reference docblock: filter
     * MUST remain `is_voided = false` only, no status filter.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ledgerApplied(ScholarshipRefrend $refrend): array
    {
        return ScholarshipWithholdingPayment::with(['withholding.createdBy'])
            ->where('applied_refrend_id', $refrend->id)
            ->where('is_voided', false)
            ->get()
            ->map(function (ScholarshipWithholdingPayment $payment) {
                $withholding = $payment->withholding;

                return [
                    'payment_id'         => $payment->id,
                    'withholding_id'     => $withholding->id,
                    'period_year'        => $withholding->period_year,
                    'period_month'       => $withholding->period_month,
                    'withheld_amount'    => $withholding->withheld_amount,
                    'amount_applied_now' => $payment->amount,
                    'remaining_amount'   => $withholding->remaining_amount,
                    'cause'              => $withholding->cause,
                    'withheld_at'        => optional($withholding->created_at)->toIso8601String(),
                    'applied_at'         => optional($payment->created_at)->toIso8601String(),
                    'created_by'         => $this->fullName($withholding->createdBy),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $ledgerApplied
     */
    private function sumAppliedNow(array $ledgerApplied): string
    {
        $sum = array_reduce(
            $ledgerApplied,
            fn (float $carry, array $row) => $carry + (float) $row['amount_applied_now'],
            0.0
        );

        return number_format(round($sum, 2), 2, '.', '');
    }

    /**
     * Kind 1b — the retention THIS refrend itself generated, if it is still
     * active. Deliberately excludes CANCELLED rows (design D2): a refrend
     * re-classified away from RETENIDA (e.g. to DESCUENTO_DEFINITIVO) leaves
     * its prior ledger row CANCELLED rather than deleted
     * (RecordPaymentSituationAction), and rendering it here would show both
     * an active-looking retention AND a definitive discount at once — the
     * exact financial misreading this change exists to prevent.
     */
    private function originWithholding(ScholarshipRefrend $refrend): ?array
    {
        $withholding = ScholarshipWithholding::with('createdBy')
            ->where('origin_refrend_id', $refrend->id)
            ->where('status', '!=', 'CANCELLED')
            ->first();

        if ($withholding === null) {
            return null;
        }

        return [
            'withholding_id'   => $withholding->id,
            'period_year'      => $withholding->period_year,
            'period_month'     => $withholding->period_month,
            'withheld_amount'  => $withholding->withheld_amount,
            'paid_amount'      => $withholding->paid_amount,
            'remaining_amount' => $withholding->remaining_amount,
            'status'           => $withholding->status,
            'cause'            => $withholding->cause,
            'withheld_at'      => optional($withholding->created_at)->toIso8601String(),
            'created_by'       => $this->fullName($withholding->createdBy),
        ];
    }

    /**
     * Kind 2 — attendance-driven discounts. No stored peso amount exists on
     * `scholarship_refrend_discounts`; never fabricate one here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function attendanceDiscounts(ScholarshipRefrend $refrend): array
    {
        return $refrend->discounts()
            ->whereIn('discount_type', [DiscountType::RETARDOS->value, DiscountType::FALTA_INJUSTIFICADA->value])
            ->get()
            ->map(fn ($discount) => [
                'id'                  => $discount->id,
                'discount_type'       => $discount->discount_type->value,
                'discount_percentage' => $discount->discount_percentage,
                'description'         => $discount->description,
                'created_at'          => optional($discount->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Kind 3 — definitive discount. Sourced directly from columns already
     * present on the refrend (`resolution_type`/`discount_amount`/
     * `discount_percentage`/`resolution_cause`) — no related-table query,
     * structurally at most one value (design D3). No decision-date field:
     * the spec confirmed none is required for this kind.
     */
    private function definitiveDiscount(ScholarshipRefrend $refrend): ?array
    {
        if ($refrend->resolution_type !== 'DESCUENTO_DEFINITIVO') {
            return null;
        }

        return [
            'discount_amount'     => $refrend->discount_amount,
            'discount_percentage' => $refrend->discount_percentage,
            'resolution_cause'    => $refrend->resolution_cause,
        ];
    }

    private function fullName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim("{$user->first_name} {$user->last_name}");

        return $name === '' ? null : $name;
    }
}
