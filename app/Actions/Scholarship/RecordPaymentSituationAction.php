<?php

namespace App\Actions\Scholarship;

use App\Enums\RefrendStatus;
use App\Exceptions\WithholdingAmountMismatchException;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Services\AttendancePenaltyService;
use App\Services\Scholarship\AdvancePaymentReconciler;
use App\Services\Scholarship\PayableWithholdingWindow;
use App\Services\Scholarship\RefrendPaymentTotalsSyncer;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

class RecordPaymentSituationAction
{
    private const ALLOWED_WORKFLOW_STATUSES = ['DRAFT', 'CON_INCIDENCIA', 'LISTO_PARA_PAGO'];

    public function __construct(
        private ScholarshipLoggingService $logging,
        private RefrendPaymentTotalsSyncer $totalsSyncer,
        private AttendancePenaltyService $attendancePenalty,
        private AdvancePaymentReconciler $advanceReconciler,
        private CloseScholarshipCycleAction $closeCycle
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
            case 'EGRESADO':
                // sdd/egresado-baja-definitiva, design D5: both are now
                // terminal cycle cuts — identical refrend-field shape. The
                // distinguishing user-level effect (active=false vs.
                // GraduateBecarioAction, design D10) is applied later, inside
                // the transaction, by CloseScholarshipCycleAction.
                $updates['final_amount']        = '0.00';
                $updates['discount_percentage'] = '100.00';
                $updates['discount_amount']     = number_format($dueAmount, 2, '.', '');
                $updates['status']              = RefrendStatus::CANCELLED->value;
                break;

            case 'REEMBOLSO_PARCIAL':
                $updates['final_amount']                = number_format($dueAmount, 2, '.', '');
                $updates['discount_percentage']         = '0.00';
                $updates['discount_amount']             = '0.00';
                $updates['refund_amount_from_previous'] = number_format((float) ($data['refund_amount'] ?? 0), 2, '.', '');
                $neutralizePenalties                    = true;
                break;
        }

        // sdd/telmex-cobertura-iu, design D4 / decisions-2 dec2: the covered
        // Telmex part is added OUTSIDE the IU-only $dueAmount computed above
        // — every branch's final_amount stays IU-only, then the covered part
        // is added here unconditionally EXCEPT for the two terminal cycle
        // cuts (sdd/egresado-baja-definitiva, design D5: BAJA_DEFINITIVA and,
        // as of that change, EGRESADO too — the refrend is CLOSED/CANCELLED,
        // never paid). SIN_PAGO/RETENIDA/SUSPENDIDA/DESCUENTO_DEFINITIVO/
        // BECA_MES/REEMBOLSO_PARCIAL all still pay the covered part — client
        // rule: "Telmex money is never discounted/withheld". The withholding
        // ledger amount above is computed from $dueAmount and therefore
        // stays IU-only, untouched.
        $covered = $refrend->coveredPayableAmount();
        if ($covered > 0 && !in_array($type, ScholarshipRefrend::CYCLE_CUT_RESOLUTIONS, true)) {
            $updates['final_amount'] = number_format(
                round((float) $updates['final_amount'] + $covered, 2),
                2,
                '.',
                ''
            );
        }

        // sdd/egresado-baja-definitiva, design D5/D10 (PR2a): both terminal
        // cuts close the refrend as CLOSED + locked instead of the generic
        // LISTO_PARA_PAGO set at the top of $updates. The user-level effect
        // (GraduateBecarioAction vs. active=false) and the profile stop
        // marker are applied inside the transaction below by
        // CloseScholarshipCycleAction.
        if (in_array($type, ScholarshipRefrend::CYCLE_CUT_RESOLUTIONS, true)) {
            $updates['workflow_status'] = 'CLOSED';
            $updates['locked_at']       = now();
            $updates['locked_by_id']    = $userId;
        }

        // Liquidation of pending retained months: selects individual ledger rows
        // (scholarship_withholdings) of THIS becario and inserts one child
        // payment row per element — replaces the old scalar-stacking mechanism.
        // final_amount is NOT touched here: total_to_pay already sums
        // amount_pending_from_previous (derived below by syncRefrendPaymentTotals).
        $paymentInputs = $data['withholding_payments'] ?? [];

        $old = $this->logging->snapshotRefrend($refrend);

        return DB::transaction(function () use ($refrend, $updates, $old, $ledgerAmount, $data, $userId, $type, $paymentInputs, $neutralizePenalties) {
            // An advance-paid month is $0 (or, on appeal, paid in full): a retention
            // ledger here could be paid out a second time via "Pago meses retenidos".
            if ($type === 'RETENIDA' && $refrend->hasLinkedAdvanceMonth()) {
                throw new \DomainException('Este mes ya se pagó por adelantado; use Sin pago (0%).');
            }

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

            // sdd/egresado-baja-definitiva, design D4/D10 (PR2a): both
            // terminal cycle cuts delegate their side effects (profile stop
            // marker + user-level effect — GraduateBecarioAction for
            // EGRESADO, active=false for BAJA_DEFINITIVA) to
            // CloseScholarshipCycleAction. This stops the becario from being
            // generated any further refrends — GenerateMonthlyRefrendsService
            // honors the marker (design D2/D3), the same way `active=false`
            // already stopped BAJA_DEFINITIVA generation pre-change.
            if (in_array($updates['resolution_type'], ScholarshipRefrend::CYCLE_CUT_RESOLUTIONS, true)) {
                $this->closeCycle->execute($refrend, $updates['resolution_type'], $userId);
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

                // Layer-2 re-check (D2 — set operation shared with the controller-layer
                // validation): a direct API call bypassing HTTP validation could still
                // reach this Action with an out-of-window/rank-excluded withholding id.
                // Computed once per call, only when there are payment inputs to process.
                $payableIds = PayableWithholdingWindow::payableIdsForUser(
                    $refrend->user_id,
                    $refrend->period_year,
                    $refrend->period_month
                );

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
                    if (!in_array((int) $withholding->id, $payableIds, true)) {
                        throw new \DomainException('Retención fuera de la ventana pagable (3 meses / 2 más recientes).');
                    }

                    $amount = round((float) $paymentInput['amount'], 2);
                    if ($amount <= 0) {
                        throw new \DomainException('Monto a pagar inválido.');
                    }
                    // Authoritative under races: re-checked here (inside the lock,
                    // against the freshly-read row) even though the controller-layer
                    // closure rule already validated the same predicate on stale data.
                    if (!$withholding->isFullSettlementAmount($amount)) {
                        throw new WithholdingAmountMismatchException();
                    }

                    ScholarshipWithholdingPayment::create([
                        'withholding_id'     => $withholding->id,
                        'applied_refrend_id' => $refrend->id,
                        // Persist the server's canonical remaining_amount, not the
                        // client-submitted number: inputs are accepted within
                        // ±SETTLEMENT_EPSILON, so echoing them back could leave a
                        // sub-cent residue that never reaches remaining_amount = 0.00.
                        'amount'             => $withholding->remaining_amount,
                        'created_by_id'      => $userId,
                    ]);

                    $withholding->recomputePaidAmount();
                }

                $this->totalsSyncer->sync($refrend);
            }

            // Advance-payment arrival reconciliation (design D4), extracted
            // into AdvancePaymentReconciler 2026-09-27 so ApproveFullPaymentAction
            // can share it too. Zero changes to the 8 branches above: this is
            // purely additive and a complete no-op for a refrend with no
            // advance history. Called inside this same transaction so a
            // diverging resolution without a reason rolls back everything
            // above it too.
            $this->advanceReconciler->reconcile($refrend, $type, $data['advance_divergence_reason'] ?? null);

            $fresh = $refrend->fresh();
            $this->logging->log($refrend, 'SITUATION_RECORDED', $old, $this->logging->snapshotRefrend($fresh));
            return $fresh;
        });
    }
}
