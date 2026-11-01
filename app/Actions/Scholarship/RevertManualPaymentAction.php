<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Enums\RefrendStatus;
use App\Exceptions\ChequePaymentException;
use App\Exceptions\ManualPaymentException;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Services\Scholarship\TelmexCoverageLedger;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

/**
 * sdd/catalogo-bancos R7 (generalizes sdd/pago-cheque PR4, spec R4 and
 * amendment A1): undoes a manual payment — cheque or other-bank transfer —
 * on ONE refrend. The channel decides which paid snapshot is required, which
 * log marks the prior state, which columns are cleared and which exception
 * type is raised (cheque keeps ChequePaymentException and its codes).
 *
 * Guards run under the row lock, in order: refrend is paid by that channel
 * (422), the becario's cycle is open (409 CYCLE_CLOSED), the covering Telmex
 * coverage (if any) has no non-voided repayments (409
 * TELMEX_REPAYMENT_EXISTS; otherwise its stored status is recomputed), then a
 * valid prior state can be read from the latest paid log of that channel
 * (422, spec R4.3).
 */
class RevertManualPaymentAction
{
    private const CHEQUE_COLUMNS = ['cheque_number', 'cheque_delivered_at', 'cheque_notes'];
    private const TRANSFER_COLUMNS = ['transfer_reference', 'transfer_paid_at', 'transfer_notes'];

    public function __construct(
        private ManualPaymentGuards $guards,
        private ScholarshipLoggingService $logging,
        private TelmexCoverageLedger $telmexLedger
    ) {
    }

    /**
     * @throws ManualPaymentException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function execute(int $refrendId, string $reason, PaymentMethod $channel): ScholarshipRefrend
    {
        $exception = $channel === PaymentMethod::CHEQUE
            ? ChequePaymentException::class
            : ManualPaymentException::class;
        $columns = $channel === PaymentMethod::CHEQUE ? self::CHEQUE_COLUMNS : self::TRANSFER_COLUMNS;

        return DB::transaction(function () use ($refrendId, $reason, $channel, $exception, $columns) {
            $refrend = ScholarshipRefrend::lockForUpdate()->findOrFail($refrendId);

            if ($refrend->status !== RefrendStatus::PAID
                || $refrend->paid_payment_method !== $channel
                || $refrend->payment_batch_id !== null) {
                throw $channel === PaymentMethod::CHEQUE
                    ? ChequePaymentException::notChequePaid()
                    : ManualPaymentException::notManualTransferPaid();
            }

            $coverage = $this->guards->assertRevertible($refrend, $exception);

            $priorStatus = $this->priorStatusFromLog($refrend, $channel);

            $old = $this->logging->snapshotRefrend($refrend) + $this->paidFields($refrend, $columns);

            $refrend->update(array_fill_keys(
                array_merge(['paid_payment_method', 'paid_batch_type', 'locked_at', 'locked_by_id'], $columns),
                null
            ) + [
                'status'          => $priorStatus->value,
                'workflow_status' => ManualPaymentGuards::RESTORABLE_WORKFLOW,
            ]);

            $refrend = $refrend->fresh();

            if ($coverage !== null) {
                // The reverted month leaves the "advanced" side of the derived ledger.
                $this->telmexLedger->recomputeStatus($coverage->fresh());
            }

            $this->logging->log(
                $refrend,
                $channel->revertLogAction(),
                $old,
                $this->logging->snapshotRefrend($refrend) + $this->paidFields($refrend, $columns),
                $reason
            );

            return $refrend;
        });
    }

    private function priorStatusFromLog(ScholarshipRefrend $refrend, PaymentMethod $channel): RefrendStatus
    {
        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', $channel->paidLogAction())
            ->orderByDesc('id')
            ->first();

        $status = RefrendStatus::tryFrom((string) ($log?->old_values['status'] ?? ''));
        $workflow = $log?->old_values['workflow_status'] ?? null;

        if ($status === null
            || !in_array($status, ManualPaymentGuards::PAYABLE_STATUSES, true)
            || $workflow !== ManualPaymentGuards::RESTORABLE_WORKFLOW) {
            throw $channel === PaymentMethod::CHEQUE
                ? ChequePaymentException::chequeLogMissing()
                : ManualPaymentException::transferLogMissing();
        }

        return $status;
    }

    /**
     * @param array<int, string> $columns
     * @return array<string, mixed>
     */
    private function paidFields(ScholarshipRefrend $refrend, array $columns): array
    {
        $fields = [
            'paid_payment_method' => $refrend->paid_payment_method?->value,
            'paid_batch_type'     => $refrend->paid_batch_type?->value,
        ];

        foreach ($columns as $column) {
            $value = $refrend->{$column};
            $fields[$column] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $fields;
    }
}
