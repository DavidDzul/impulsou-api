<?php

namespace App\Services\Scholarship;

/**
 * sdd/retenciones-pendientes: outcome of CycleTerminationCanceller.
 * closedRefrendIds are the refrends closed because their advance month was
 * cancelled (user deactivation only; empty for the automatic egreso).
 */
final class CycleTerminationResult
{
    /**
     * @param array<int, int> $closedRefrendIds
     */
    public function __construct(
        public readonly WithholdingCancellationResult $withholdings,
        public readonly AdvanceCutPlan $advancePlan,
        public readonly array $closedRefrendIds
    ) {
    }
}
