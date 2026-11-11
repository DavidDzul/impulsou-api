<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Exceptions\ManualPaymentException;
use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendManualPayment;
use App\Services\ScholarshipLoggingService;
use App\Support\Banking\BatchBank;
use Illuminate\Support\Facades\DB;

/**
 * sdd/catalogo-bancos R7: closes ONE refrend as paid by a transfer to a
 * non-BBVA bank, made outside the bank file. Same effects as a cheque payment
 * (PAID / CLOSED / locked_*, no payment_batch_id) with the transfer metadata
 * and the MANUAL_TRANSFER paid-method snapshot.
 *
 * sdd/bank-file-split-other-banks-cheques PR2b: the transfer data lives in the
 * child table (one row per concept with amount > 0, written atomically with
 * the paid state by ManualPaymentRecorder); the legacy transfer_* columns are
 * no longer written.
 *
 * Guards run under the row lock, in order: already paid (409), the becario's
 * channel is MANUAL_TRANSFER, i.e. a TRANSFER method with a non-batch bank
 * (422 NOT_MANUAL_TRANSFER_METHOD), payability incl. a valid CLABE matching
 * the bank (422 NOT_PAYABLE), then the recorder's checks (409
 * CONCEPT_SPLIT_CHANGED, 422 references, 422 TRANSFER_REFERENCE_TAKEN; a
 * separate namespace from cheque references).
 */
class MarkManualTransferPaidAction
{
    public function __construct(
        private ManualPaymentGuards $guards,
        private ManualPaymentRecorder $recorder,
        private ScholarshipLoggingService $logging
    ) {
    }

    /**
     * @param array<string, mixed> $references      concept => reference
     * @param array<string, mixed> $expectedAmounts concept => decimal
     *
     * @throws ManualPaymentException
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(
        int $refrendId,
        int $userId,
        array $references,
        array $expectedAmounts,
        ?string $paidAt,
        ?string $notes
    ): ScholarshipRefrend {
        return DB::transaction(function () use ($refrendId, $userId, $references, $expectedAmounts, $paidAt, $notes) {
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

            $rows = $this->recorder->record(
                $refrend,
                PaymentMethod::MANUAL_TRANSFER,
                $userId,
                $references,
                $expectedAmounts,
                $paidAt,
                $notes,
                fn (string $concept) => ManualPaymentException::transferReferenceTaken($concept)
            );

            $old = $this->logging->snapshotRefrend($refrend);

            $refrend->update($this->guards->closeAttributes(
                $userId,
                PaymentMethod::MANUAL_TRANSFER,
                $this->guards->frozenBatchType($refrend)
            ));

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
                    'manual_payments'     => array_map(
                        fn (ScholarshipRefrendManualPayment $row) => ManualPaymentGuards::snapshotRow($row),
                        $rows
                    ),
                ]
            );

            return $refrend;
        });
    }
}
