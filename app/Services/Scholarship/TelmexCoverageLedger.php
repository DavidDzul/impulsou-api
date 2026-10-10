<?php

namespace App\Services\Scholarship;

use App\Enums\RefrendStatus;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use Illuminate\Database\Eloquent\Builder;

/**
 * sdd/telmex-cobertura-iu, design D8. Derived ledger — no stored
 * owed/paid columns. advanced/repaid/balance are always computed from the
 * source-of-truth rows (paid covered refrends, non-voided payments), and
 * recomputeStatus() is the SOLE writer of TelmexCoverage::status, mirroring
 * ScholarshipWithholding::recomputePaidAmount()'s drift-proof pattern.
 */
class TelmexCoverageLedger
{
    /** Tolerance for float/rounding drift (mirrors ScholarshipWithholding::SETTLEMENT_EPSILON). */
    public const EPSILON = 0.005;

    /**
     * Covered refrends of `$coverage`, excluding EGRESO_RETICULA resolutions
     * (spec "interactions" domain: excluded from covered-months counting
     * and debt, same precedent as Filter C elsewhere).
     */
    private function coveredRefrendsQuery(TelmexCoverage $coverage): Builder
    {
        return ScholarshipRefrend::where('snapshot_telmex_coverage_id', $coverage->id)
            ->where(function (Builder $query) {
                $query->whereNull('resolution_type')
                    ->orWhere('resolution_type', '!=', ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA);
            });
    }

    /** Paid predicate — mirrors PaymentBatchService::partitionGroup(). */
    private function paidPredicate(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNotNull('payment_batch_id')
                ->orWhere('status', RefrendStatus::PAID->value);
        });
    }

    public function advanced(TelmexCoverage $coverage): float
    {
        $sum = $this->paidPredicate($this->coveredRefrendsQuery($coverage))
            ->sum('snapshot_telmex_covered_amount');

        return round((float) $sum, 2);
    }

    public function repaid(TelmexCoverage $coverage): float
    {
        $sum = $coverage->payments()->where('is_voided', false)->sum('amount');

        return round((float) $sum, 2);
    }

    public function balance(TelmexCoverage $coverage): float
    {
        return max(0.0, round($this->advanced($coverage) - $this->repaid($coverage), 2));
    }

    /** Used by ReactivateTelmexCoverageAction's guard (decisions-2 dec3). */
    public function hasPaidCoveredMonth(TelmexCoverage $coverage): bool
    {
        return $this->paidPredicate($this->coveredRefrendsQuery($coverage))->exists();
    }

    /** Used by EndTelmexCoverageAction to reject an end_period before the last paid covered month. */
    public function lastPaidCoveredPeriodAbsolute(TelmexCoverage $coverage): ?int
    {
        $row = $this->paidPredicate($this->coveredRefrendsQuery($coverage))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->first(['period_year', 'period_month']);

        return $row ? ((int) $row->period_year) * 12 + (int) $row->period_month : null;
    }

    /**
     * Any covered row at/under end_period that is still unpaid and not
     * CANCELLED — if one exists, the coverage cannot be LIQUIDADA yet even
     * when repaid >= advanced (Telmex may still owe that month).
     */
    private function hasUnpaidCoveredRowWithinEndPeriod(TelmexCoverage $coverage): bool
    {
        if ($coverage->end_period === null) {
            return false;
        }

        $endAbsolute = ((int) $coverage->end_period->year) * 12 + (int) $coverage->end_period->month;

        return $this->coveredRefrendsQuery($coverage)
            ->whereNull('payment_batch_id')
            ->where('status', '!=', RefrendStatus::PAID->value)
            ->where('status', '!=', RefrendStatus::CANCELLED->value)
            ->get(['period_year', 'period_month'])
            ->contains(fn ($row) => ((int) $row->period_year) * 12 + (int) $row->period_month <= $endAbsolute);
    }

    /**
     * Design D8: CANCELADA is terminal (never recomputed away). Otherwise
     * end_period NULL means the coverage is still open -> ACTIVA. Once
     * closed, LIQUIDADA iff something was actually advanced, repaid caught
     * up (within EPSILON) AND no covered month in the window is still
     * unpaid; otherwise EN_COBRO. This naturally reverts LIQUIDADA ->
     * EN_COBRO when a settling payment is later voided — no special case
     * needed, same as the withholding ledger's precedent.
     */
    public function recomputeStatus(TelmexCoverage $coverage): TelmexCoverage
    {
        if ($coverage->status === 'CANCELADA') {
            return $coverage;
        }

        if ($coverage->end_period === null) {
            $newStatus = 'ACTIVA';
        } else {
            $advanced = $this->advanced($coverage);
            $repaid   = $this->repaid($coverage);
            $settled  = $advanced > 0 && ($repaid + self::EPSILON) >= $advanced;

            $newStatus = ($settled && !$this->hasUnpaidCoveredRowWithinEndPeriod($coverage)) ? 'LIQUIDADA' : 'EN_COBRO';
        }

        if ($newStatus !== $coverage->status) {
            $coverage->status     = $newStatus;
            $coverage->settled_at = $newStatus === 'LIQUIDADA' ? ($coverage->settled_at ?? now()) : null;
            $coverage->save();
        }

        return $coverage;
    }
}
