<?php

namespace App\Actions\Scholarship;

use App\Enums\RefrendStatus;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Services\AttendancePenaltyService;
use App\Services\Scholarship\RefrendPaymentTotalsSyncer;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class RecordPaymentSituationAction
{
    private const ALLOWED_WORKFLOW_STATUSES = ['DRAFT', 'CON_INCIDENCIA', 'LISTO_PARA_PAGO'];

    public function __construct(
        private ScholarshipLoggingService $logging,
        private RefrendPaymentTotalsSyncer $totalsSyncer,
        private AttendancePenaltyService $attendancePenalty
    ) {}

    /**
     * Percentage-or-fixed-amount discount math shared by RETENIDA and
     * DESCUENTO_DEFINITIVO. Returns the refrend field updates plus the raw
     * withheld amount (callers decide whether it feeds the withholding
     * ledger / status side effects — this method has none).
     *
     * @return array{updates: array<string, string>, withheld: float}
     */
    private function applyWithholdingDiscount(array $data, float $dueAmount): array
    {
        $mode  = $data['withholding_mode'] ?? 'percentage';
        $value = (float) ($data['withholding_value'] ?? 100);
        $withheld = $mode === 'fixed'
            ? min($dueAmount, round($value, 2))
            : round($dueAmount * (min(100.0, max(0.0, $value)) / 100), 2);
        $pct = $dueAmount > 0 ? round($withheld / $dueAmount * 100, 2) : 0.0;

        return [
            'updates' => [
                'withholding_mode'    => $mode,
                'withholding_value'   => number_format($value, 2, '.', ''),
                'discount_amount'     => number_format($withheld, 2, '.', ''),
                'discount_percentage' => number_format($pct, 2, '.', ''),
                'final_amount'        => number_format(round($dueAmount - $withheld, 2), 2, '.', ''),
            ],
            'withheld' => $withheld,
        ];
    }

    public function execute(ScholarshipRefrend $refrend, array $data, int $userId): ScholarshipRefrend
    {
        if (!in_array($refrend->workflow_status, self::ALLOWED_WORKFLOW_STATUSES)) {
            throw new \DomainException(
                'Solo se puede registrar la situación en refrendos en estado DRAFT, CON_INCIDENCIA o LISTO_PARA_PAGO.'
            );
        }

        $type = $data['resolution_type'];

        // Amount actually due this month: gross snapshot with the active profile
        // discount applied — mirrors ScholarshipCalculationService::calculateFinalAmount's
        // $base. NOT the raw base_amount (ignores the profile discount) and NOT
        // $refrend->final_amount (may already carry a previous resolution's effect on
        // this same refrend, which would compound errors on re-resolution). Falls back
        // to base_amount when snapshot_gross_amount isn't set (legacy rows).
        $gross       = (float) ($refrend->snapshot_gross_amount ?? $refrend->base_amount);
        $academicPct = (float) ($refrend->snapshot_discount_percentage ?? 0);
        $dueAmount   = round($gross * (1 - $academicPct / 100), 2);

        $updates = [
            'workflow_status'         => 'LISTO_PARA_PAGO',
            'resolution_type'         => $type,
            'resolution_cause'        => $data['resolution_cause'] ?? null,
            'resolution_notes'        => $data['resolution_notes'] ?? null,
            'atencion_reviewed_by_id' => $userId,
            'atencion_reviewed_at'    => now(),
        ];

        // Set only when the resolution creates/updates the retention ledger row
        // (case RETENIDA). Materialized inside the transaction below.
        $ledgerAmount = null;

        // Set true for full-amount resolution types (final_amount = $dueAmount
        // with no stored discount) so active RETARDOS/FALTA_INJUSTIFICADA rows
        // don't survive orphaned against a final_amount that no longer reflects
        // them (D7). SIN_PAGO/RETENIDA/SUSPENDIDA/BAJA_DEFINITIVA are excluded:
        // their stored amount is intentionally reduced by those rows.
        $neutralizePenalties = false;

        switch ($type) {
            case 'BECA_MES':
                $updates['final_amount']        = number_format($dueAmount, 2, '.', '');
                $updates['discount_percentage'] = '0.00';
                $updates['discount_amount']     = '0.00';
                $neutralizePenalties            = true;
                break;

            case 'SIN_PAGO':
                $updates['final_amount']        = '0.00';
                $updates['discount_percentage'] = '100.00';
                $updates['discount_amount']     = number_format($dueAmount, 2, '.', '');
                break;

            case 'RETENIDA':
                ['updates' => $withholdingUpdates, 'withheld' => $withheld] = $this->applyWithholdingDiscount($data, $dueAmount);
                $updates      = array_merge($updates, $withholdingUpdates);
                $updates['status'] = RefrendStatus::WITHHELD->value;
                $ledgerAmount       = $withheld;
                break;

            case 'DESCUENTO_DEFINITIVO':
                ['updates' => $withholdingUpdates] = $this->applyWithholdingDiscount($data, $dueAmount);
                $updates = array_merge($updates, $withholdingUpdates);
                break;

            case 'SUSPENDIDA':
                $pct     = (float) ($data['suspension_percentage'] ?? 0);
                $reduced = round($dueAmount * (1 - $pct / 100), 2);
                $updates['suspension_percentage'] = $pct;
                $updates['final_amount']          = number_format($reduced, 2, '.', '');
                $updates['discount_percentage']   = number_format($pct, 2, '.', '');
                $updates['discount_amount']       = number_format(round($dueAmount * $pct / 100, 2), 2, '.', '');
                break;

            case 'BAJA_DEFINITIVA':
                $updates['final_amount']        = '0.00';
                $updates['discount_percentage'] = '100.00';
                $updates['discount_amount']     = number_format($dueAmount, 2, '.', '');
                $updates['status']              = RefrendStatus::CANCELLED->value;
                break;

            case 'EGRESADO':
                $updates['final_amount']        = number_format($dueAmount, 2, '.', '');
                $updates['discount_percentage'] = '0.00';
                $updates['discount_amount']     = '0.00';
                $neutralizePenalties            = true;
                break;

            case 'REEMBOLSO_PARCIAL':
                $updates['final_amount']                = number_format($dueAmount, 2, '.', '');
                $updates['discount_percentage']         = '0.00';
                $updates['discount_amount']             = '0.00';
                $updates['refund_amount_from_previous'] = number_format((float) ($data['refund_amount'] ?? 0), 2, '.', '');
                $neutralizePenalties                    = true;
                break;
        }

        // Liquidation of pending retained months: selects individual ledger rows
        // (scholarship_withholdings) of THIS becario and inserts one child
        // payment row per element — replaces the old scalar-stacking mechanism.
        // final_amount is NOT touched here: total_to_pay already sums
        // amount_pending_from_previous (derived below by syncRefrendPaymentTotals).
        $paymentInputs = $data['withholding_payments'] ?? [];

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $updates, $old, $ledgerAmount, $data, $userId, $paymentInputs, $neutralizePenalties) {
            // Re-classification guard: a retention that already has payments applied
            // against it (paid_amount > 0) cannot be silently overwritten or
            // cancelled by re-registering the situation — the amounts already
            // liquidated would become orphaned. Locked and checked inside the same
            // transaction as the mutation below to close the check-then-act window
            // (two concurrent recordSituation calls on the same refrend could
            // otherwise both pass the guard before either one writes).
            $existingLedger = ScholarshipWithholding::where('origin_refrend_id', $refrend->id)
                ->lockForUpdate()
                ->first();
            if ($existingLedger && (float) $existingLedger->paid_amount > 0) {
                throw new \DomainException('No se puede modificar una retención que ya tiene pagos aplicados.');
            }

            $refrend->update($updates);

            if ($neutralizePenalties) {
                $this->attendancePenalty->neutralizeAttendancePenalties($refrend);
            }

            if ($ledgerAmount !== null && $ledgerAmount > 0) {
                ScholarshipWithholding::updateOrCreate(
                    ['origin_refrend_id' => $refrend->id],
                    [
                        'user_id'         => $refrend->user_id,
                        'period_year'     => $refrend->period_year,
                        'period_month'    => $refrend->period_month,
                        'withheld_amount' => number_format($ledgerAmount, 2, '.', ''),
                        'status'          => 'PENDING',
                        'cause'           => $data['resolution_cause'] ?? null,
                        'created_by_id'   => $userId,
                    ]
                );
            } elseif ($existingLedger && $existingLedger->status !== 'CANCELLED') {
                // Re-classified away from RETENIDA. Guarded above: paid_amount is
                // guaranteed to be 0 here, so cancelling is safe and orphans nothing.
                $existingLedger->update(['status' => 'CANCELLED']);
            }

            if ($paymentInputs) {
                if (
                    ScholarshipWithholdingPayment::where('applied_refrend_id', $refrend->id)
                        ->where('is_voided', false)
                        ->exists()
                ) {
                    throw new \DomainException(
                        'Este refrendo ya tiene abonos aplicados. Revierta los abonos antes de volver a registrar la situación.'
                    );
                }

                foreach ($paymentInputs as $paymentInput) {
                    $withholding = ScholarshipWithholding::lockForUpdate()->findOrFail($paymentInput['withholding_id']);

                    if ((int) $withholding->user_id !== (int) $refrend->user_id) {
                        throw new \DomainException('Retención de otro becario.');
                    }
                    if ((int) $withholding->origin_refrend_id === (int) $refrend->id) {
                        throw new \DomainException('Un refrendo no puede liquidar su propia retención.');
                    }
                    if ($withholding->status !== 'PENDING') {
                        throw new \DomainException('Retención ya liquidada o cancelada.');
                    }

                    $amount = round(min((float) $paymentInput['amount'], (float) $withholding->remaining_amount), 2);
                    if ($amount <= 0) {
                        throw new \DomainException('Monto a pagar inválido.');
                    }

                    ScholarshipWithholdingPayment::create([
                        'withholding_id'     => $withholding->id,
                        'applied_refrend_id' => $refrend->id,
                        'amount'             => number_format($amount, 2, '.', ''),
                        'created_by_id'      => $userId,
                    ]);

                    $withholding->recomputePaidAmount();
                }

                $this->totalsSyncer->sync($refrend);
            }

            $fresh = $refrend->fresh();
            $this->logging->log($refrend, 'SITUATION_RECORDED', $old, $this->logging->snapshotRefrend($fresh));
            return $fresh;
        });
    }
}
