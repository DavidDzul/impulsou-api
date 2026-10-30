<?php

namespace App\Services\Scholarship;

use App\Enums\CycleClosureReason;
use App\Enums\WithholdingCancellationReason;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;

/**
 * Spec R16 (sdd/revertir-pago-adelantado), extracted from
 * CloseScholarshipCycleAction by sdd/retenciones-pendientes so the manual cut,
 * the automatic egreso and the user deactivation share it: one
 * ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT log per affected origin refrend
 * (several headers of the same origin cannot exist: the origin->header
 * relation is unique). Runs after the canceller applied the plan, so the
 * "old" origin amount is reconstructed from the reduction.
 */
class AdvanceCancellationLogger
{
    public function __construct(private ScholarshipLoggingService $logging)
    {
    }

    /** Human label of the cancellation trigger used in log notes and cascade notes. */
    public static function label(string $reason): string
    {
        return match ($reason) {
            CycleClosureReason::EGRESADO->value                    => 'EGRESO',
            WithholdingCancellationReason::EGRESO_RETICULA->value  => 'EGRESO POR RETÍCULA',
            WithholdingCancellationReason::USER_DEACTIVATED->value => 'DESACTIVACIÓN DE USUARIO',
            default                                                => 'BAJA DEFINITIVA',
        };
    }

    public function log(CutAnchor $cut, AdvanceCutPlan $plan, string $reason): void
    {
        foreach ($plan->origins as $row) {
            $origin = ScholarshipRefrend::find($row['origin_refrend_id']);
            if ($origin === null) {
                continue;
            }

            $months = array_values(array_map(
                fn (array $m) => [
                    'period_year'  => $m['period_year'],
                    'period_month' => $m['period_month'],
                    'amount'       => $m['amount'],
                ],
                array_filter($plan->months, fn (array $m) => $m['header_id'] === $row['header_id'])
            ));

            $currentAmount = bcadd((string) ($origin->advance_payment_amount ?? '0'), '0', 2);

            $this->logging->log(
                $origin,
                'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT',
                [
                    'header_id'              => $row['header_id'],
                    'header_status'          => ScholarshipAdvancePayment::STATUS_ACTIVE,
                    'advance_payment_amount' => bcadd($currentAmount, $row['advance_payment_reduction'], 2),
                ],
                [
                    'header_id'                 => $row['header_id'],
                    'header_status'             => $row['resulting_header_status'],
                    'months'                    => $months,
                    'cancelled_amount'          => $row['cancelled_amount'],
                    'origin_paid'               => $row['origin_paid'],
                    'overpaid_amount'           => $row['overpaid_amount'],
                    'advance_payment_reduction' => $row['advance_payment_reduction'],
                    'advance_payment_amount'    => $currentAmount,
                    'cut_refrend_id'            => $cut->cutRefrendId,
                    'cut_reason'                => $reason,
                ],
                sprintf(
                    'Pago adelantado cancelado por %s del %02d/%d.',
                    self::label($reason),
                    $cut->month,
                    $cut->year
                )
            );
        }
    }
}
