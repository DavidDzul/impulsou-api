<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Exceptions\ChequePaymentException;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

/**
 * sdd/pago-cheque PR4 (spec R4, amendment A1): undoes a cheque payment on ONE
 * refrend.
 *
 * Guards run under the row lock, in order: refrend exists (404 by the
 * controller), it is cheque-paid (422 NOT_CHEQUE_PAID), the becario's cycle is
 * open (409 CYCLE_CLOSED — same marker as CloseScholarshipCycleAction; no
 * profile means allowed), then a valid prior state can be read from the latest
 * CHEQUE_PAID log (422 CHEQUE_LOG_MISSING — spec R4.3; this supersedes the
 * design D14 fallback).
 */
class RevertChequePaymentAction
{
    private const RESTORABLE_STATUSES = [RefrendStatus::AUTHORIZED, RefrendStatus::WITHHELD];
    private const RESTORABLE_WORKFLOW = 'LISTO_PARA_PAGO';

    public function __construct(private ScholarshipLoggingService $logging)
    {
    }

    /**
     * @throws ChequePaymentException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(int $refrendId, int $userId, string $reason): ScholarshipRefrend
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

            $priorStatus = $this->priorStatusFromLog($refrend);

            $old = $this->logging->snapshotRefrend($refrend) + $this->chequeFields($refrend);

            $refrend->update([
                'status'              => $priorStatus->value,
                'workflow_status'     => self::RESTORABLE_WORKFLOW,
                'locked_at'           => null,
                'locked_by_id'        => null,
                'paid_payment_method' => null,
                'cheque_number'       => null,
                'cheque_delivered_at' => null,
                'cheque_notes'        => null,
            ]);

            $refrend = $refrend->fresh();

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
            'cheque_number'       => $refrend->cheque_number,
            'cheque_delivered_at' => $refrend->cheque_delivered_at?->toDateString(),
            'cheque_notes'        => $refrend->cheque_notes,
        ];
    }
}
