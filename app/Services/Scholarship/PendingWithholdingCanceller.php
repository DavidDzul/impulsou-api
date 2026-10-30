<?php

namespace App\Services\Scholarship;

use App\Enums\WithholdingCancellationReason;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipWithholding;
use Illuminate\Support\Facades\DB;

/**
 * sdd/retenciones-pendientes (R2): cancels every PENDING retention of a
 * becario. Invoked explicitly by each trigger (manual cut, automatic egreso,
 * user deactivation); there is no User observer.
 *
 * Rows are locked FOR UPDATE ordered by id and the status is re-read under the
 * lock, then flipped with a conditional update (where status = PENDING), so a
 * second call is a no-op and never double-logs. PAID and CANCELLED rows are
 * never touched. A partially paid row is cancelled too: its paid_amount stays
 * frozen (recomputePaidAmount skips CANCELLED) and no abono is deleted.
 * Must run inside the caller's transaction (nested is fine).
 */
class PendingWithholdingCanceller
{
    private const SCALE = 2;

    public function cancelAll(int $userId, WithholdingCancellationReason $reason, ?int $actorId): WithholdingCancellationResult
    {
        return DB::transaction(function () use ($userId, $reason, $actorId) {
            $rows = ScholarshipWithholding::where('user_id', $userId)
                ->pending()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return WithholdingCancellationResult::empty();
            }

            $now       = now();
            $ids       = [];
            $total     = '0.00';

            foreach ($rows as $row) {
                $updated = ScholarshipWithholding::where('id', $row->id)
                    ->where('status', 'PENDING')
                    ->update([
                        'status'              => 'CANCELLED',
                        'cancelled_at'        => $now,
                        'cancellation_reason' => $reason->value,
                        'cancelled_by_id'     => $actorId,
                        'updated_at'          => $now,
                    ]);

                if ($updated === 0) {
                    continue;
                }

                $remaining = bcsub(
                    bcadd((string) $row->withheld_amount, '0', self::SCALE),
                    bcadd((string) $row->paid_amount, '0', self::SCALE),
                    self::SCALE
                );

                $this->log($row, $reason, $actorId, $remaining);

                $ids[]  = (int) $row->id;
                $total  = bcadd($total, $remaining, self::SCALE);
            }

            return new WithholdingCancellationResult(count($ids), $ids, $total);
        });
    }

    private function log(ScholarshipWithholding $row, WithholdingCancellationReason $reason, ?int $actorId, string $remaining): void
    {
        ScholarshipRefrendLog::create([
            'scholarship_refrend_id' => $row->origin_refrend_id,
            'performed_by_id'        => $actorId,
            'action'                 => 'WITHHOLDING_CANCELLED',
            'old_values'             => [
                'withholding_id' => $row->id,
                'status'         => 'PENDING',
                'paid_amount'    => $row->paid_amount,
            ],
            'new_values'             => [
                'withholding_id'      => $row->id,
                'status'              => 'CANCELLED',
                'cancellation_reason' => $reason->value,
                'withheld_amount'     => $row->withheld_amount,
                'paid_amount'         => $row->paid_amount,
                'remaining_amount'    => $remaining,
            ],
            'notes'                  => sprintf(
                'Retención de %02d/%d cancelada (%s). Saldo cancelado: %s.',
                $row->period_month,
                $row->period_year,
                $reason->value,
                $remaining
            ),
        ]);
    }
}
