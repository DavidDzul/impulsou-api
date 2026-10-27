<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Exceptions\ChequePaymentException;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Services\Scholarship\PaymentReadinessEvaluator;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

/**
 * sdd/pago-cheque PR3 (spec R3, design "Actions"): closes ONE refrend as paid
 * by cheque. Mirrors BulkPayAction's effects (PAID / CLOSED / locked_*) but
 * never stamps payment_batch_id — a cheque never belongs to a transfer batch
 * (spec R2.7) — and records the cheque metadata plus the paid-method snapshot.
 *
 * Guards run under the row lock, in order: already paid (409), becario method
 * is CHEQUE (422), then payability (422): resolution never a batch candidate,
 * status DRAFT/WITHHELD only (INVALID_STATUS; CANCELLED and PAID are
 * blocked), then
 * PaymentReadinessEvaluator with PaymentMethod::CHEQUE (which also enforces
 * Filter A / Telmex candidacy); finally the cheque number must not belong to
 * another cheque-paid refrend (422 CHEQUE_NUMBER_TAKEN).
 */
class MarkChequePaidAction
{
    /**
     * Statuses a cheque can be registered from — exactly the set
     * RevertChequePaymentAction can restore, so mark/revert round-trip.
     *
     * Approval only moves workflow_status to LISTO_PARA_PAGO and leaves status
     * DRAFT (WITHHELD for RETENIDA). Approval readiness is enforced by
     * PaymentReadinessEvaluator (workflow LISTO_PARA_PAGO).
     */
    public const PAYABLE_STATUSES = [RefrendStatus::DRAFT, RefrendStatus::WITHHELD];

    public function __construct(
        private PaymentReadinessEvaluator $evaluator,
        private ScholarshipLoggingService $logging
    ) {
    }

    /**
     * @throws ChequePaymentException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(
        int $refrendId,
        int $userId,
        string $chequeNumber,
        ?string $deliveredAt,
        ?string $notes
    ): ScholarshipRefrend {
        $chequeNumber = trim($chequeNumber);

        return DB::transaction(function () use ($refrendId, $userId, $chequeNumber, $deliveredAt, $notes) {
            $refrend = ScholarshipRefrend::with('user')->lockForUpdate()->findOrFail($refrendId);

            if ($refrend->status === RefrendStatus::PAID
                || $refrend->locked_at !== null
                || $refrend->payment_batch_id !== null) {
                throw ChequePaymentException::alreadyPaid();
            }

            $paymentData = ScholarshipPaymentData::where('user_id', $refrend->user_id)->first();

            if ($paymentData?->payment_method !== PaymentMethod::CHEQUE) {
                throw ChequePaymentException::notChequeMethod();
            }

            if (in_array($refrend->resolution_type, ScholarshipRefrend::NON_CANDIDATE_RESOLUTIONS, true)) {
                throw ChequePaymentException::notPayable([
                    ['code' => 'NOT_BATCH_CANDIDATE', 'message' => 'El refrendo no es candidato a pago'],
                ]);
            }

            if (!in_array($refrend->status, self::PAYABLE_STATUSES, true)) {
                throw ChequePaymentException::notPayable([
                    ['code' => 'INVALID_STATUS', 'message' => 'El refrendo no está autorizado para pago'],
                ]);
            }

            $evaluation = $this->evaluator->evaluate(
                $refrend,
                !empty($refrend->user?->enrollment),
                true,
                null,
                $paymentData->rfc,
                PaymentMethod::CHEQUE
            );

            if (!$evaluation['is_payable']) {
                throw ChequePaymentException::notPayable($evaluation['blocking_reasons']);
            }

            // A cheque number identifies ONE currently cheque-paid refrend
            // (trimmed, case-insensitive). A revert clears cheque_number, so
            // its number becomes reusable. The locking read narrows the race
            // window between two concurrent marks; there is no unique index
            // because the rule is conditional on the paid state.
            $taken = ScholarshipRefrend::where('paid_payment_method', PaymentMethod::CHEQUE->value)
                ->where('id', '!=', $refrend->id)
                ->whereRaw('LOWER(TRIM(cheque_number)) = ?', [mb_strtolower($chequeNumber)])
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw ChequePaymentException::chequeNumberTaken();
            }

            $old = $this->logging->snapshotRefrend($refrend);

            $refrend->update([
                'status'              => RefrendStatus::PAID->value,
                'workflow_status'     => 'CLOSED',
                'locked_at'           => now(),
                'locked_by_id'        => $userId,
                'paid_payment_method' => PaymentMethod::CHEQUE->value,
                'cheque_number'       => $chequeNumber,
                'cheque_delivered_at' => $deliveredAt ?? now()->toDateString(),
                'cheque_notes'        => $notes,
            ]);

            $refrend = $refrend->fresh();

            $this->logging->log(
                $refrend,
                'CHEQUE_PAID',
                $old,
                $this->logging->snapshotRefrend($refrend) + [
                    'paid_payment_method' => PaymentMethod::CHEQUE->value,
                    'cheque_number'       => $refrend->cheque_number,
                    'cheque_delivered_at' => $refrend->cheque_delivered_at?->toDateString(),
                    'cheque_notes'        => $refrend->cheque_notes,
                ]
            );

            return $refrend;
        });
    }
}
