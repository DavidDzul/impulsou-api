<?php

namespace App\Actions\Scholarship;

use App\Enums\RefrendStatus;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Services\Scholarship\PaymentReadinessEvaluator;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Facades\DB;

/**
 * Extracted from ScholarshipRefrendController::bulkPay() (PR3,
 * sdd/becario-payment-file-generation). Mirrors BulkApproveAction's shape
 * (constructor-injected, execute(ids, userId): array), but returns
 * paid/skipped/errors (analogous to BulkApproveAction's
 * approved/skipped/errors).
 *
 * Runs inside one DB::transaction() with lockForUpdate() on the rows being
 * processed, then re-evaluates each record's eligibility under the lock via
 * PaymentReadinessEvaluator — the same evaluator PaymentBatchService uses for
 * the pre-payment review list (PR2) — instead of duplicating the inline
 * WITHHELD && final_amount <= 0 check that used to live in bulkPay(). This
 * guards against a race between reading readiness and writing the payment
 * (design D2: "the all-or-nothing rule is re-checked per-record inside
 * BulkPayAction under the row lock").
 *
 * $batch is optional: the legacy bulk/pay route (this PR) has no batch
 * concept and calls execute() without it; a later PR's atomic
 * ScholarshipPaymentController::process() will pass a persisted
 * ScholarshipPaymentBatch so payment_batch_id gets stamped.
 */
class BulkPayAction
{
    public function __construct(
        private PaymentReadinessEvaluator $evaluator,
        private ScholarshipLoggingService $logging
    ) {
    }

    /**
     * @return array{paid: int, skipped: int, errors: array<int, array{id: int, reason: string}>}
     */
    public function execute(array $ids, int $userId, ?ScholarshipPaymentBatch $batch = null): array
    {
        return DB::transaction(function () use ($ids, $userId, $batch) {
            $paid    = 0;
            $skipped = 0;
            $errors  = [];

            $refrends = ScholarshipRefrend::whereIn('id', $ids)
                ->lockForUpdate()
                ->with('user')
                ->get();

            // Full rows (not just ids) — PR3 needs account_number/rfc to
            // feed PaymentReadinessEvaluator's now-required bank-data check
            // (sdd/becario-payment-bank-file-export/design D2).
            $paymentDataByUserId = ScholarshipPaymentData::whereIn('user_id', $refrends->pluck('user_id'))
                ->get()
                ->keyBy('user_id');

            foreach ($refrends as $refrend) {
                $hasEnrollment  = !empty($refrend->user?->enrollment);
                $paymentData    = $paymentDataByUserId->get($refrend->user_id);
                $hasPaymentData = $paymentData !== null;

                $evaluation = $this->evaluator->evaluate(
                    $refrend,
                    $hasEnrollment,
                    $hasPaymentData,
                    $paymentData?->account_number,
                    $paymentData?->rfc
                );

                if (!$evaluation['is_payable']) {
                    $skipped++;
                    $errors[] = [
                        'id'     => $refrend->id,
                        'reason' => implode('; ', array_column($evaluation['blocking_reasons'], 'message')),
                    ];
                    continue;
                }

                $old = $this->logging->snapshotRefrend($refrend);

                $refrend->update([
                    'status'           => RefrendStatus::PAID->value,
                    'workflow_status'  => 'CLOSED',
                    'locked_at'        => now(),
                    'locked_by_id'     => $userId,
                    'payment_batch_id' => $batch?->id,
                ]);

                $this->logging->log(
                    $refrend,
                    'BULK_PAY',
                    $old,
                    $this->logging->snapshotRefrend($refrend->fresh())
                );

                $paid++;
            }

            return compact('paid', 'skipped', 'errors');
        });
    }
}
