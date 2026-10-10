<?php

namespace App\Services\Scholarship;

use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Services\ScholarshipCalculationService;

/**
 * sdd/telmex-cobertura-iu, design D9. Keeps already-generated refrends in
 * sync with a coverage's current window (start_period..end_period, or
 * detached entirely once CANCELADA) WITHOUT touching paid/locked rows
 * (financial history is immutable) and WITHOUT wiping discount/penalty
 * rows — RecalculateRefrendService::fullRecalculate() was rejected as the
 * mechanism (DRAFT-only, destructive); ScholarshipCalculationService::
 * recalculate() reapplies the standard formula from existing
 * ScholarshipRefrendDiscount rows instead.
 *
 * MUST be called AFTER the coverage's start_period/end_period/status are
 * finalized and saved, inside the SAME DB::transaction as the caller
 * (Activate/End/Cancel/Reactivate actions) so a blocking error rolls back
 * the whole operation.
 */
class TelmexCoverageRefrendSync
{
    public function __construct(private ScholarshipCalculationService $calculator) {}

    public function sync(TelmexCoverage $coverage): void
    {
        $candidates = ScholarshipRefrend::where('user_id', $coverage->user_id)
            ->whereIn('snapshot_scholarship_type', [ScholarshipType::TELMEX->value, ScholarshipType::TELMEX_IU->value])
            ->where(function ($query) use ($coverage) {
                $query->where('snapshot_telmex_coverage_id', $coverage->id)
                    ->orWhereNull('snapshot_telmex_coverage_id');
            })
            ->lockForUpdate()
            ->get();

        $blockedPeriods = [];
        $toApply        = [];

        foreach ($candidates as $row) {
            $currentlyCovered = (int) $row->snapshot_telmex_coverage_id === (int) $coverage->id;
            $shouldBeCovered  = $this->isWithinWindow($row, $coverage);

            if ($currentlyCovered === $shouldBeCovered) {
                continue;
            }

            $isPaidOrLocked = $row->payment_batch_id !== null
                || $row->status === RefrendStatus::PAID
                || $row->locked_at !== null;
            if ($isPaidOrLocked) {
                continue;
            }

            // Design D9: a resolved row (resolution_type set) must never be
            // silently recalculated here — RecordPaymentSituationAction's
            // per-resolution formula is NOT reproduced by
            // ScholarshipCalculationService::recalculate(), so applying it
            // blindly would overwrite a staff-entered resolution amount.
            // Staff must clear the resolution first.
            if ($row->resolution_type !== null) {
                $blockedPeriods[] = "{$row->period_month}/{$row->period_year}";
                continue;
            }

            $toApply[] = ['row' => $row, 'covered' => $shouldBeCovered];
        }

        if ($blockedPeriods !== []) {
            $list = implode(', ', $blockedPeriods);
            throw new \DomainException(
                "No se puede sincronizar la cobertura: los siguientes periodos ya tienen una situación registrada y deben limpiarse primero: {$list}."
            );
        }

        foreach ($toApply as $item) {
            $row                                     = $item['row'];
            $row->snapshot_telmex_coverage_id = $item['covered'] ? $coverage->id : null;
            $row->save();
            $this->calculator->recalculate($row);
        }
    }

    /** Mirrors TelmexCoverageResolver's D1 applicability window. */
    private function isWithinWindow(ScholarshipRefrend $row, TelmexCoverage $coverage): bool
    {
        if ($coverage->status === 'CANCELADA') {
            return false;
        }

        $rowAbsolute   = ((int) $row->period_year) * 12 + (int) $row->period_month;
        $startAbsolute = ((int) $coverage->start_period->year) * 12 + (int) $coverage->start_period->month;

        if ($rowAbsolute < $startAbsolute) {
            return false;
        }

        if ($coverage->end_period === null) {
            return true;
        }

        $endAbsolute = ((int) $coverage->end_period->year) * 12 + (int) $coverage->end_period->month;

        return $rowAbsolute <= $endAbsolute;
    }
}
