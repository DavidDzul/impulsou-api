<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentBatchType;
use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Exceptions\ManualPaymentException;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Services\Scholarship\PaymentReadinessEvaluator;

/**
 * sdd/catalogo-bancos R7: the guards and locks every manually-paid channel
 * (cheque, other-bank transfer) runs inside its own transaction. Extracted
 * from MarkChequePaidAction / RevertChequePaymentAction so both channels
 * apply identical rules. Each method throws the exception class the caller
 * passes (ChequePaymentException for cheques) so cheque codes stay unchanged.
 *
 * Every method assumes it runs inside a DB transaction.
 */
class ManualPaymentGuards
{
    /**
     * Statuses a manual payment can be registered from — exactly the set a
     * revert can restore, so mark/revert round-trip.
     *
     * Approval only moves workflow_status to LISTO_PARA_PAGO and leaves status
     * DRAFT (WITHHELD for RETENIDA).
     */
    public const PAYABLE_STATUSES = [RefrendStatus::DRAFT, RefrendStatus::WITHHELD];

    public const RESTORABLE_WORKFLOW = 'LISTO_PARA_PAGO';

    public function __construct(private PaymentReadinessEvaluator $evaluator)
    {
    }

    /**
     * Locks the refrend (with its user) and rejects one already paid or
     * locked, or already inside a transfer batch (409 ALREADY_PAID).
     *
     * @param class-string<ManualPaymentException> $exception
     */
    public function lockUnpaid(int $refrendId, string $exception = ManualPaymentException::class): ScholarshipRefrend
    {
        $refrend = ScholarshipRefrend::with('user')->lockForUpdate()->findOrFail($refrendId);

        if ($refrend->status === RefrendStatus::PAID
            || $refrend->locked_at !== null
            || $refrend->payment_batch_id !== null) {
            throw $exception::alreadyPaid();
        }

        return $refrend;
    }

    /**
     * Payability under the lock: resolution never a batch candidate, status
     * DRAFT/WITHHELD only, then PaymentReadinessEvaluator for the channel
     * (which also enforces Filter A / Telmex candidacy).
     *
     * @param class-string<ManualPaymentException> $exception
     */
    public function assertPayable(
        ScholarshipRefrend $refrend,
        ScholarshipPaymentData $paymentData,
        PaymentMethod $channel,
        ?string $accountNumber,
        ?string $bankCode,
        string $exception = ManualPaymentException::class
    ): void {
        if (in_array($refrend->resolution_type, ScholarshipRefrend::NON_CANDIDATE_RESOLUTIONS, true)) {
            throw $exception::notPayable([
                ['code' => 'NOT_BATCH_CANDIDATE', 'message' => 'El refrendo no es candidato a pago'],
            ]);
        }

        if (!in_array($refrend->status, self::PAYABLE_STATUSES, true)) {
            throw $exception::notPayable([
                ['code' => 'INVALID_STATUS', 'message' => 'El refrendo no está autorizado para pago'],
            ]);
        }

        $evaluation = $this->evaluator->evaluate(
            $refrend,
            !empty($refrend->user?->enrollment),
            true,
            $accountNumber,
            $paymentData->rfc,
            $channel,
            $bankCode
        );

        if (!$evaluation['is_payable']) {
            throw $exception::notPayable($evaluation['blocking_reasons']);
        }
    }

    /**
     * Log-friendly copy of a child row (paid mark and revert snapshots).
     *
     * @return array<string, mixed>
     */
    public static function snapshotRow(\App\Models\ScholarshipRefrendManualPayment $row): array
    {
        return [
            'concept' => $row->concept,
            'method'  => $row->method,
            'reference' => $row->reference,
            'amount'  => (string) $row->amount,
            'paid_at' => $row->paid_at?->toDateString(),
            'notes'   => $row->notes,
        ];
    }

    /**
     * Freezes the segment at payment time (sdd/cert-unid-historial R2): a
     * later flag flip must not move this paid row between tabs.
     */
    public function frozenBatchType(ScholarshipRefrend $refrend): PaymentBatchType
    {
        $isCertUnid = (bool) ScholarshipProfile::where('user_id', $refrend->user_id)
            ->value('is_cert_unid');

        return PaymentBatchType::forCertUnidFlag($isCertUnid);
    }

    /**
     * Attributes that close a refrend as paid by a manual channel. Never
     * stamps payment_batch_id (spec R2.7).
     *
     * @return array<string, mixed>
     */
    public function closeAttributes(int $userId, PaymentMethod $channel, PaymentBatchType $batchType): array
    {
        return [
            'status'              => RefrendStatus::PAID->value,
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now(),
            'locked_by_id'        => $userId,
            'paid_payment_method' => $channel->value,
            'paid_batch_type'     => $batchType->value,
        ];
    }

    /**
     * Revert guards under the lock: cycle open (409 CYCLE_CLOSED) and no
     * non-voided Telmex repayments on the covering coverage (409
     * TELMEX_REPAYMENT_EXISTS). Returns the locked coverage, if any, so the
     * caller recomputes its status after the revert.
     *
     * @param class-string<ManualPaymentException> $exception
     */
    public function assertRevertible(ScholarshipRefrend $refrend, string $exception = ManualPaymentException::class): ?TelmexCoverage
    {
        $profile = ScholarshipProfile::where('user_id', $refrend->user_id)->first();
        if ($profile !== null && $profile->cycle_closed_period !== null) {
            throw $exception::cycleClosed();
        }

        $coverage = $refrend->snapshot_telmex_coverage_id === null
            ? null
            : TelmexCoverage::lockForUpdate()->find($refrend->snapshot_telmex_coverage_id);

        if ($coverage !== null && $coverage->payments()->where('is_voided', false)->exists()) {
            throw $exception::telmexRepaymentExists();
        }

        return $coverage;
    }
}
