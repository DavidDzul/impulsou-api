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

    /**
     * Read-only dry run of cancelAll(): same scope and order, no lock, no write.
     * Rows follow the cut preview contract; the cut refrend's own retention is
     * flagged with is_current_refrend.
     *
     * @return array{rows: array<int, array<string, mixed>>, total_remaining: string}
     */
    public function preview(int $userId, ?int $cutRefrendId = null): array
    {
        $rows  = [];
        $total = '0.00';

        foreach (ScholarshipWithholding::where('user_id', $userId)->pending()->orderBy('id')->get() as $w) {
            $rows[] = [
                'id'                 => $w->id,
                'period_year'        => $w->period_year,
                'period_month'       => $w->period_month,
                'withheld_amount'    => $w->withheld_amount,
                'paid_amount'        => $w->paid_amount,
                'remaining_amount'   => $w->remaining_amount,
                'is_current_refrend' => $cutRefrendId !== null && (int) $w->origin_refrend_id === $cutRefrendId,
            ];
            $total = bcadd($total, (string) $w->remaining_amount, self::SCALE);
        }

        return ['rows' => $rows, 'total_remaining' => $total];
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
