<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Exceptions\ChequePaymentException;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
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
 * another cheque-paid refrend (422 CHEQUE_NUMBER_TAKEN). The shared guards
 * live in ManualPaymentGuards (sdd/catalogo-bancos).
 */
class MarkChequePaidAction
{
    /**
     * Statuses a cheque can be registered from — exactly the set
     * RevertChequePaymentAction can restore, so mark/revert round-trip.
     */
    public const PAYABLE_STATUSES = ManualPaymentGuards::PAYABLE_STATUSES;

    public function __construct(
        private ManualPaymentGuards $guards,
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
            $refrend = $this->guards->lockUnpaid($refrendId, ChequePaymentException::class);

            $paymentData = ScholarshipPaymentData::where('user_id', $refrend->user_id)->first();

            if ($paymentData?->payment_method !== PaymentMethod::CHEQUE) {
                throw ChequePaymentException::notChequeMethod();
            }

            $this->guards->assertPayable(
                $refrend,
                $paymentData,
                PaymentMethod::CHEQUE,
                null,
                null,
                ChequePaymentException::class
            );

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

            $refrend->update($this->guards->closeAttributes(
                $userId,
                PaymentMethod::CHEQUE,
                $this->guards->frozenBatchType($refrend)
            ) + [
                'cheque_number'       => $chequeNumber,
                'cheque_delivered_at' => $deliveredAt ?? now()->toDateString(),
                'cheque_notes'        => $notes,
            ]);

            $refrend = $refrend->fresh();

            $this->logging->log(
                $refrend,
                PaymentMethod::CHEQUE->paidLogAction(),
                $old,
                $this->logging->snapshotRefrend($refrend) + [
                    'paid_payment_method' => PaymentMethod::CHEQUE->value,
                    'paid_batch_type'     => $refrend->paid_batch_type?->value,
                    'cheque_number'       => $refrend->cheque_number,
                    'cheque_delivered_at' => $refrend->cheque_delivered_at?->toDateString(),
                    'cheque_notes'        => $refrend->cheque_notes,
                ]
            );

            return $refrend;
        });
    }
}
