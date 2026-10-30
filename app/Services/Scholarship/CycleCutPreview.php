<?php

namespace App\Services\Scholarship;

/**
 * sdd/revertir-pago-adelantado, S6 (spec R20): immutable result of previewing
 * a manual cycle cut without performing it. Built by
 * CloseScholarshipCycleAction::preview() from the collected guard blockers and
 * the canceller's plan. Amounts are 2-decimal strings.
 */
final class CycleCutPreview
{
    /** @param array<int, CycleCutBlocker> $blockers */
    public function __construct(
        public readonly string $reason,
        public readonly array $blockers,
        public readonly AdvanceCutPlan $plan
    ) {
    }

    public function canCut(): bool
    {
        return $this->blockers === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $originsByHeader = [];
        foreach ($this->plan->origins as $origin) {
            $originsByHeader[$origin['header_id']] = $origin;
        }

        return [
            'reason'   => $this->reason,
            'can_cut'  => $this->canCut(),
            'blockers' => array_map(fn (CycleCutBlocker $b) => $b->toArray(), $this->blockers),
            'advance'  => [
                'months'  => array_map(fn (array $m) => [
                    'period_year'         => $m['period_year'],
                    'period_month'        => $m['period_month'],
                    'amount'              => $m['amount'],
                    'is_cut_month'        => $m['is_cut_month'],
                    'origin_period_year'  => $originsByHeader[$m['header_id']]['origin_period_year'],
                    'origin_period_month' => $originsByHeader[$m['header_id']]['origin_period_month'],
                ], $this->plan->months),
                'origins' => array_map(fn (array $o) => [
                    'origin_period_year'        => $o['origin_period_year'],
                    'origin_period_month'       => $o['origin_period_month'],
                    'origin_paid'               => $o['origin_paid'],
                    'cancelled_amount'          => $o['cancelled_amount'],
                    'overpaid_amount'           => $o['overpaid_amount'],
                    'advance_payment_reduction' => $o['advance_payment_reduction'],
                ], $this->plan->origins),
                'cancelled_amount'          => $this->plan->cancelledAmount,
                'overpaid_amount'           => $this->plan->overpaidAmount,
                'advance_payment_reduction' => $this->plan->advancePaymentReduction,
            ],
        ];
    }
}
