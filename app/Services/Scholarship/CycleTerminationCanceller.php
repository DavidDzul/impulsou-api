<?php

namespace App\Services\Scholarship;

use App\Enums\CycleClosureReason;
use App\Enums\RefrendStatus;
use App\Enums\WithholdingCancellationReason;
use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * sdd/retenciones-pendientes: single orchestrator shared by the automatic
 * egreso (EGRESO_RETICULA, system actor) and the user deactivation
 * (USER_DEACTIVATED, admin actor). The manual cut keeps orchestrating inside
 * CloseScholarshipCycleAction because its guard chain and cascade differ.
 *
 * Inside one transaction: cancel the PENDING retentions, cancel the advance
 * payments from the anchor month on, write the advance audit log and, for a
 * deactivation only, close the refrends born from the cancelled advance months
 * ($0, CANCELLED/CLOSED, locked, note "Cancelado por DESACTIVACIÓN DE USUARIO
 * del MM/YYYY") so they do not turn into payable normal refrends. A blocked
 * advance (CycleCutBlockedException) rolls the whole thing back.
 *
 * Closed refrends carry resolution_type BAJA_DEFINITIVA, like the manual cut
 * cascade, so every amount breakdown treats them as a $0 cycle cut; the note
 * carries the real reason.
 */
class CycleTerminationCanceller
{
    public function __construct(
        private PendingWithholdingCanceller $withholdings,
        private AdvanceCycleCutCanceller $advances,
        private AdvanceCancellationLogger $advanceLogger,
        private ScholarshipLoggingService $logging
    ) {
    }

    public function cancel(CutAnchor $anchor, WithholdingCancellationReason $reason, ?int $actorId): CycleTerminationResult
    {
        if (! in_array($reason, [WithholdingCancellationReason::EGRESO_RETICULA, WithholdingCancellationReason::USER_DEACTIVATED], true)) {
            throw new InvalidArgumentException('CycleTerminationCanceller only handles EGRESO_RETICULA and USER_DEACTIVATED.');
        }

        return DB::transaction(function () use ($anchor, $reason, $actorId) {
            $withholdingResult = $this->withholdings->cancelAll($anchor->userId, $reason, $actorId);
            $plan              = $this->advances->cancelAfterCut($anchor, $reason->value, $actorId);

            $this->advanceLogger->log($anchor, $plan, $reason->value);

            $closed = $reason === WithholdingCancellationReason::USER_DEACTIVATED
                ? $this->closeAdvanceRefrends($anchor, $plan, $reason, $actorId)
                : [];

            return new CycleTerminationResult($withholdingResult, $plan, $closed);
        });
    }

    /**
     * @return array<int, int> ids of the refrends closed
     */
    private function closeAdvanceRefrends(CutAnchor $anchor, AdvanceCutPlan $plan, WithholdingCancellationReason $reason, ?int $actorId): array
    {
        $ids = collect($plan->months)
            ->pluck('refrend_id')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $closed = [];
        $note   = sprintf('Cancelado por %s del %02d/%d', AdvanceCancellationLogger::label($reason->value), $anchor->month, $anchor->year);

        foreach ($ids as $id) {
            /** @var ScholarshipRefrend|null $refrend */
            $refrend = ScholarshipRefrend::find($id);
            if ($refrend === null) {
                continue;
            }

            $gross       = (float) ($refrend->snapshot_gross_amount ?? $refrend->base_amount);
            $academicPct = (float) ($refrend->snapshot_discount_percentage ?? 0);
            $due         = round($gross * (1 - $academicPct / 100), 2);

            $refrend->update([
                'resolution_type'     => CycleClosureReason::BAJA_DEFINITIVA->value,
                'resolution_notes'    => $note,
                'final_amount'        => '0.00',
                'discount_percentage' => '100.00',
                'discount_amount'     => number_format($due, 2, '.', ''),
                'status'              => RefrendStatus::CANCELLED->value,
                'workflow_status'     => 'CLOSED',
                'locked_at'           => now(),
                'locked_by_id'        => $actorId,
            ]);

            $this->logging->log($refrend, 'CYCLE_CLOSED_CASCADE', null, null, $note . '.');

            $closed[] = (int) $id;
        }

        return $closed;
    }
}
