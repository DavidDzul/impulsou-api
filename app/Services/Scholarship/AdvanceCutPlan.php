<?php

namespace App\Services\Scholarship;

/**
 * sdd/revertir-pago-adelantado, design A2: immutable result of planning the
 * cancellation of advance payments caused by a manual cycle cut. Produced by
 * AdvanceCycleCutCanceller::plan() (pure), returned by preview() and
 * cancelAfterCut(). Every amount is a 2-decimal string (no float drift).
 *
 * months:  [{id, header_id, refrend_id, period_year, period_month, amount, is_cut_month}]
 *          ordered by period, then id.
 * origins: [{header_id, origin_refrend_id, origin_period_year, origin_period_month, origin_paid,
 *            cancelled_amount, overpaid_amount, advance_payment_reduction, resulting_header_status}]
 *          ordered by header id. advance_payment_reduction is the amount actually
 *          taken off the origin (already floored at the origin's current amount).
 * advanceRefrendIds: refrends created by the cancelled advance months strictly
 *          AFTER the cut period (the cut refrend itself is never cascaded),
 *          ordered by period. These are the ids the cycle-cut cascade closes
 *          with the "Cancelado por ..." note.
 */
final class AdvanceCutPlan
{
    /**
     * @param array<int, array<string, mixed>> $months
     * @param array<int, array<string, mixed>> $origins
     * @param array<int, int>                  $advanceRefrendIds
     */
    public function __construct(
        public readonly array $months,
        public readonly array $origins,
        public readonly string $cancelledAmount,
        public readonly string $overpaidAmount,
        public readonly string $advancePaymentReduction,
        public readonly array $advanceRefrendIds
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], '0.00', '0.00', '0.00', []);
    }

    public function isEmpty(): bool
    {
        return $this->months === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'months'                    => $this->months,
            'origins'                   => $this->origins,
            'cancelled_amount'          => $this->cancelledAmount,
            'overpaid_amount'           => $this->overpaidAmount,
            'advance_payment_reduction' => $this->advancePaymentReduction,
            'advance_refrend_ids'       => $this->advanceRefrendIds,
        ];
    }
}
