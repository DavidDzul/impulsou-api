<?php

namespace App\Actions\Scholarship;

use App\Enums\RefrendStatus;
use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class RecordPaymentSituationAction
{
    private const ALLOWED_WORKFLOW_STATUSES = ['DRAFT', 'CON_INCIDENCIA', 'LISTO_PARA_PAGO'];

    public function __construct(private ScholarshipLoggingService $logging) {}

    public function execute(ScholarshipRefrend $refrend, array $data, int $userId): ScholarshipRefrend
    {
        if (!in_array($refrend->workflow_status, self::ALLOWED_WORKFLOW_STATUSES)) {
            throw new \DomainException(
                'Solo se puede registrar la situación en refrendos en estado DRAFT, CON_INCIDENCIA o LISTO_PARA_PAGO.'
            );
        }

        $type       = $data['resolution_type'];
        $baseAmount = (float) $refrend->base_amount;

        $updates = [
            'workflow_status'         => 'LISTO_PARA_PAGO',
            'resolution_type'         => $type,
            'resolution_cause'        => $data['resolution_cause'] ?? null,
            'resolution_notes'        => $data['resolution_notes'] ?? null,
            'atencion_reviewed_by_id' => $userId,
            'atencion_reviewed_at'    => now(),
        ];

        switch ($type) {
            case 'BECA_MES':
                $updates['final_amount']        = number_format($baseAmount, 2, '.', '');
                $updates['discount_percentage'] = '0.00';
                $updates['discount_amount']     = '0.00';
                break;

            case 'SIN_PAGO':
                $updates['final_amount']        = '0.00';
                $updates['discount_percentage'] = '100.00';
                $updates['discount_amount']     = number_format($baseAmount, 2, '.', '');
                break;

            case 'RETENIDA':
                $mode  = $data['withholding_mode'] ?? 'percentage';
                $value = (float) ($data['withholding_value'] ?? 100);
                $withheld = $mode === 'fixed'
                    ? min($baseAmount, round($value, 2))
                    : round($baseAmount * (min(100.0, max(0.0, $value)) / 100), 2);
                $pct = $baseAmount > 0 ? round($withheld / $baseAmount * 100, 2) : 0.0;

                $updates['withholding_mode']    = $mode;
                $updates['withholding_value']   = number_format($value, 2, '.', '');
                $updates['discount_amount']     = number_format($withheld, 2, '.', '');
                $updates['discount_percentage'] = number_format($pct, 2, '.', '');
                $updates['final_amount']        = number_format(round($baseAmount - $withheld, 2), 2, '.', '');
                $updates['status']              = RefrendStatus::WITHHELD->value;
                break;

            case 'SUSPENDIDA':
                $pct     = (float) ($data['suspension_percentage'] ?? 0);
                $reduced = round($baseAmount * (1 - $pct / 100), 2);
                $updates['suspension_percentage'] = $pct;
                $updates['final_amount']          = number_format($reduced, 2, '.', '');
                $updates['discount_percentage']   = number_format($pct, 2, '.', '');
                $updates['discount_amount']       = number_format(round($baseAmount * $pct / 100, 2), 2, '.', '');
                break;

            case 'BAJA_DEFINITIVA':
                $updates['final_amount']        = '0.00';
                $updates['discount_percentage'] = '100.00';
                $updates['discount_amount']     = number_format($baseAmount, 2, '.', '');
                $updates['status']              = RefrendStatus::CANCELLED->value;
                break;

            case 'EGRESADO':
                $updates['final_amount']        = number_format($baseAmount, 2, '.', '');
                $updates['discount_percentage'] = '0.00';
                $updates['discount_amount']     = '0.00';
                break;

            case 'REEMBOLSO_PARCIAL':
                $updates['final_amount']                = number_format($baseAmount, 2, '.', '');
                $updates['discount_percentage']         = '0.00';
                $updates['discount_amount']             = '0.00';
                $updates['refund_amount_from_previous'] = number_format((float) ($data['refund_amount'] ?? 0), 2, '.', '');
                break;
        }

        // Stack catch-up carryover payment on top of the primary situation amount.
        // Also zero out amount_pending_from_previous so total_to_pay doesn't
        // double-count the pending that is already incorporated here.
        // REEMBOLSO_PARCIAL is purely additive — carryover stacking must not run for it.
        $carryoverCount = (int) ($data['carryover_months_count'] ?? 0);
        if ($carryoverCount > 0) {
            $pct          = min(100.0, max(1.0, (float) ($data['carryover_percentage'] ?? 100)));
            $currentFinal = isset($updates['final_amount'])
                ? (float) $updates['final_amount']
                : (float) $refrend->final_amount;
            $updates['final_amount']                = number_format(
                round($currentFinal + $baseAmount * $carryoverCount * ($pct / 100), 2),
                2, '.', ''
            );
            $updates['carryover_months_count']      = $carryoverCount;
            $updates['carryover_months_detail']     = $data['carryover_months_detail'] ?? null;
            $updates['carryover_percentage']        = $pct;
            $updates['amount_pending_from_previous'] = '0.00';
        }

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $updates, $old) {
            $refrend->update($updates);
            $fresh = $refrend->fresh();
            $this->logging->log($refrend, 'SITUATION_RECORDED', $old, $this->logging->snapshotRefrend($fresh));
            return $fresh;
        });
    }
}
