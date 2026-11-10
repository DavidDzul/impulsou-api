<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Exceptions\ChequePaymentException;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendManualPayment;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

/**
 * sdd/pago-cheque PR3 (spec R3, design "Actions"): closes ONE refrend as paid
 * by cheque. Mirrors BulkPayAction's effects (PAID / CLOSED / locked_*) but
 * never stamps payment_batch_id — a cheque never belongs to a transfer batch
 * (spec R2.7) — and records the paid-method snapshot.
 *
 * sdd/bank-file-split-other-banks-cheques PR2a: the cheque data lives in the
 * child table (one row per concept with amount > 0, written atomically with
 * the paid state by ManualPaymentRecorder); the legacy cheque_* columns are no
 * longer written.
 *
 * Guards run under the row lock, in order: already paid (409), becario method
 * is CHEQUE (422), payability (422, ManualPaymentGuards), then the recorder's
 * checks (409 CONCEPT_SPLIT_CHANGED, 422 references, 422 CHEQUE_REFERENCE_TAKEN).
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
        private ManualPaymentRecorder $recorder,
        private ScholarshipLoggingService $logging
    ) {
    }

    /**
     * @param array<string, mixed> $references      concept => reference
     * @param array<string, mixed> $expectedAmounts concept => decimal
     *
     * @throws ChequePaymentException
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

            $rows = $this->recorder->record(
                $refrend,
                PaymentMethod::CHEQUE,
                $userId,
                $references,
                $expectedAmounts,
                $paidAt,
                $notes,
                fn (string $concept) => ChequePaymentException::chequeReferenceTaken($concept),
                ChequePaymentException::class
            );

            $old = $this->logging->snapshotRefrend($refrend);

            $refrend->update($this->guards->closeAttributes(
                $userId,
                PaymentMethod::CHEQUE,
                $this->guards->frozenBatchType($refrend)
            ));

            $refrend = $refrend->fresh();

            $this->logging->log(
                $refrend,
                PaymentMethod::CHEQUE->paidLogAction(),
                $old,
                $this->logging->snapshotRefrend($refrend) + [
                    'paid_payment_method' => PaymentMethod::CHEQUE->value,
                    'paid_batch_type'     => $refrend->paid_batch_type?->value,
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
