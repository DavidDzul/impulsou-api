<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Exceptions\ManualPaymentException;
use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipLoggingService;
use App\Support\Banking\BatchBank;
use Illuminate\Support\Facades\DB;

/**
 * sdd/catalogo-bancos R7: closes ONE refrend as paid by a transfer to a
 * non-BBVA bank, made outside the bank file. Same effects as a cheque payment
 * (PAID / CLOSED / locked_*, no payment_batch_id) with the transfer metadata
 * and the MANUAL_TRANSFER paid-method snapshot.
 *
 * Guards run under the row lock, in order: already paid (409), the becario's
 * channel is MANUAL_TRANSFER, i.e. a TRANSFER method with a non-batch bank
 * (422 NOT_MANUAL_TRANSFER_METHOD), payability incl. a valid CLABE matching
 * the bank (422 NOT_PAYABLE), and finally the reference must not belong to
 * another currently manual-transfer-paid refrend (422
 * TRANSFER_REFERENCE_TAKEN; a separate namespace from cheque numbers).
 */
class MarkManualTransferPaidAction
{
    public function __construct(
        private ManualPaymentGuards $guards,
        private ScholarshipLoggingService $logging
    ) {
    }

    /**
     * @throws ManualPaymentException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(
        int $refrendId,
        int $userId,
        string $reference,
        ?string $paidAt,
        ?string $notes
    ): ScholarshipRefrend {
        $reference = trim($reference);

        return DB::transaction(function () use ($refrendId, $userId, $reference, $paidAt, $notes) {
            $refrend = $this->guards->lockUnpaid($refrendId);

            $paymentData = ScholarshipPaymentData::where('user_id', $refrend->user_id)->first();
            $bank        = $paymentData?->bank_id !== null ? Bank::find($paymentData->bank_id) : null;

            if ($paymentData === null
                || $paymentData->payment_method === null
                || BatchBank::channelFor($paymentData->payment_method, $bank?->code) !== PaymentMethod::MANUAL_TRANSFER) {
                throw ManualPaymentException::notManualTransferMethod();
            }

            $this->guards->assertPayable(
                $refrend,
                $paymentData,
                PaymentMethod::MANUAL_TRANSFER,
                $paymentData->account_number,
                $bank->code
            );

            // A reference identifies ONE currently manual-transfer-paid
            // refrend (trimmed, case-insensitive). A revert clears it, so it
            // becomes reusable. The locking read narrows the race window
            // between two concurrent marks.
            $taken = ScholarshipRefrend::where('paid_payment_method', PaymentMethod::MANUAL_TRANSFER->value)
                ->where('id', '!=', $refrend->id)
                ->whereRaw('LOWER(TRIM(transfer_reference)) = ?', [mb_strtolower($reference)])
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw ManualPaymentException::transferReferenceTaken();
            }

            $old = $this->logging->snapshotRefrend($refrend);

            $refrend->update($this->guards->closeAttributes(
                $userId,
                PaymentMethod::MANUAL_TRANSFER,
                $this->guards->frozenBatchType($refrend)
            ) + [
                'transfer_reference' => $reference,
                'transfer_paid_at'   => $paidAt ?? now()->toDateString(),
                'transfer_notes'     => $notes,
            ]);

            $refrend = $refrend->fresh();

            $this->logging->log(
                $refrend,
                PaymentMethod::MANUAL_TRANSFER->paidLogAction(),
                $old,
                $this->logging->snapshotRefrend($refrend) + [
                    'paid_payment_method' => PaymentMethod::MANUAL_TRANSFER->value,
                    'paid_batch_type'     => $refrend->paid_batch_type?->value,
                    'bank_code'           => $bank->code,
                    'bank_name'           => $bank->name,
                    'clabe'               => $paymentData->account_number,
                    'transfer_reference'  => $refrend->transfer_reference,
                    'transfer_paid_at'    => $refrend->transfer_paid_at?->toDateString(),
                    'transfer_notes'      => $refrend->transfer_notes,
                ]
            );

            return $refrend;
        });
    }
}
