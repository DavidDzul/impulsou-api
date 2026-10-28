<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Exceptions\ChequePaymentException;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\TelmexCoverage;
use App\Services\Scholarship\TelmexCoverageLedger;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

/**
 * sdd/pago-cheque PR4 (spec R4, amendment A1): undoes a cheque payment on ONE
 * refrend.
 *
 * Guards run under the row lock, in order: refrend exists (404 by the
 * controller), it is cheque-paid (422 NOT_CHEQUE_PAID), the becario's cycle is
 * open (409 CYCLE_CLOSED — same marker as CloseScholarshipCycleAction; no
 * profile means allowed), the covering Telmex coverage (if any) has no
 * non-voided repayments (409 TELMEX_REPAYMENT_EXISTS; otherwise its stored
 * status is recomputed after the revert), then a valid prior state can be read from the latest
 * CHEQUE_PAID log (422 CHEQUE_LOG_MISSING — spec R4.3; this supersedes the
 * design D14 fallback).
 */
class RevertChequePaymentAction
{
    private const RESTORABLE_STATUSES = MarkChequePaidAction::PAYABLE_STATUSES;
    private const RESTORABLE_WORKFLOW = 'LISTO_PARA_PAGO';

    public function __construct(
        private ScholarshipLoggingService $logging,
        private TelmexCoverageLedger $telmexLedger
    ) {
    }

    /**
     * @throws ChequePaymentException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(int $refrendId, string $reason): ScholarshipRefrend
    {
        return DB::transaction(function () use ($refrendId, $reason) {
            $refrend = ScholarshipRefrend::lockForUpdate()->findOrFail($refrendId);

            if ($refrend->status !== RefrendStatus::PAID
                || $refrend->paid_payment_method !== PaymentMethod::CHEQUE
                || $refrend->payment_batch_id !== null) {
                throw ChequePaymentException::notChequePaid();
            }

            $profile = ScholarshipProfile::where('user_id', $refrend->user_id)->first();
            if ($profile !== null && $profile->cycle_closed_period !== null) {
                throw ChequePaymentException::cycleClosed();
            }

            $coverage = $this->lockedTelmexCoverage($refrend);
            if ($coverage !== null && $coverage->payments()->where('is_voided', false)->exists()) {
                throw ChequePaymentException::telmexRepaymentExists();
            }

            $priorStatus = $this->priorStatusFromLog($refrend);

            $old = $this->logging->snapshotRefrend($refrend) + $this->chequeFields($refrend);

            $refrend->update([
                'status'              => $priorStatus->value,
                'workflow_status'     => self::RESTORABLE_WORKFLOW,
                'locked_at'           => null,
                'locked_by_id'        => null,
                'paid_payment_method' => null,
                'paid_batch_type'     => null,
                'cheque_number'       => null,
                'cheque_delivered_at' => null,
                'cheque_notes'        => null,
            ]);

            $refrend = $refrend->fresh();

            if ($coverage !== null) {
                // The reverted month leaves the "advanced" side of the derived ledger.
                $this->telmexLedger->recomputeStatus($coverage->fresh());
            }

            $this->logging->log(
                $refrend,
                'CHEQUE_PAYMENT_REVERTED',
                $old,
                $this->logging->snapshotRefrend($refrend) + $this->chequeFields($refrend),
                $reason
            );

            return $refrend;
        });
    }

    private function lockedTelmexCoverage(ScholarshipRefrend $refrend): ?TelmexCoverage
    {
        if ($refrend->snapshot_telmex_coverage_id === null) {
            return null;
        }

        return TelmexCoverage::lockForUpdate()->find($refrend->snapshot_telmex_coverage_id);
    }

    private function priorStatusFromLog(ScholarshipRefrend $refrend): RefrendStatus
    {
        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', 'CHEQUE_PAID')
            ->orderByDesc('id')
            ->first();

        $status = RefrendStatus::tryFrom((string) ($log?->old_values['status'] ?? ''));
        $workflow = $log?->old_values['workflow_status'] ?? null;

        if ($status === null
            || !in_array($status, self::RESTORABLE_STATUSES, true)
            || $workflow !== self::RESTORABLE_WORKFLOW) {
            throw ChequePaymentException::chequeLogMissing();
        }

        return $status;
    }

    private function chequeFields(ScholarshipRefrend $refrend): array
    {
        return [
            'paid_payment_method' => $refrend->paid_payment_method?->value,
            'paid_batch_type'     => $refrend->paid_batch_type?->value,
            'cheque_number'       => $refrend->cheque_number,
            'cheque_delivered_at' => $refrend->cheque_delivered_at?->toDateString(),
            'cheque_notes'        => $refrend->cheque_notes,
        ];
    }
}
