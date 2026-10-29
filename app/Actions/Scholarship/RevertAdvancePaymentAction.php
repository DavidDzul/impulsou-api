<?php

namespace App\Actions\Scholarship;

use App\Exceptions\AdvancePaymentException;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Services\Scholarship\AdvanceRevertEligibility;
use App\Services\ScholarshipLoggingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * sdd/revertir-pago-adelantado (spec R1-R5): hard-reverts ONE advance payment.
 *
 * Deletes the header (its months cascade) and every future refrend the
 * advance created (logs, incidents, discounts, adjustments, withholdings
 * cascade by FK), and zeroes the origin's advance_payment_amount. The origin
 * is otherwise untouched: workflow_status, incidents, resolution, discounts
 * and final_amount stay as they were.
 *
 * Concurrency: an unlocked snapshot (header id + month ids/statuses) is taken
 * first. Inside one transaction the rows are locked in a fixed order (origin,
 * futures by ascending id, header, months); if the header or its months no
 * longer match the snapshot the request fails with ADVANCE_STATE_CHANGED,
 * otherwise AdvanceRevertEligibility re-runs under the locks and throws the
 * specific blocking code.
 */
class RevertAdvancePaymentAction
{
    public function __construct(
        private ScholarshipLoggingService $logging,
        private AdvanceRevertEligibility $eligibility
    ) {
    }

    /**
     * @throws AdvancePaymentException
     */
    public function execute(int $originRefrendId, string $reason): ScholarshipRefrend
    {
        // Cheap unlocked checks: surfaces the specific code without taking locks.
        $this->eligibility->assertEligible($originRefrendId);

        $snapshot = $this->snapshotAdvance($originRefrendId);

        $this->afterSnapshot();

        return DB::transaction(function () use ($originRefrendId, $reason, $snapshot) {
            $origin = ScholarshipRefrend::whereKey($originRefrendId)->lockForUpdate()->first();
            if ($origin === null) {
                throw AdvancePaymentException::notFound();
            }

            $futures = ScholarshipRefrend::whereIn('id', $snapshot['future_ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $header = ScholarshipAdvancePayment::where('origin_refrend_id', $originRefrendId)
                ->lockForUpdate()
                ->first();

            $months = $header === null
                ? collect()
                : ScholarshipAdvancePaymentMonth::where('advance_payment_id', $header->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            if (! $this->matchesSnapshot($snapshot, $header, $months, $futures)) {
                throw AdvancePaymentException::stateChanged();
            }

            // Authoritative re-check under the locks.
            $this->eligibility->assertEligible($originRefrendId);

            $old = $this->logging->snapshotRefrend($origin) + [
                'advance_payment_amount' => $origin->advance_payment_amount,
                'advance_payment'        => $this->headerSnapshot($header, $months),
                'deleted_refrend_ids'    => $futures->pluck('id')->all(),
            ];

            $header->delete();
            foreach ($futures as $future) {
                $future->delete();
            }

            $origin->update(['advance_payment_amount' => 0]);

            $origin = $origin->fresh();

            $this->logging->log(
                $origin,
                'ADVANCE_PAYMENT_REVERTED',
                $old,
                $this->logging->snapshotRefrend($origin) + [
                    'advance_payment_amount' => $origin->advance_payment_amount,
                    'advance_payment'        => null,
                ],
                $reason
            );

            return $origin;
        });
    }

    /**
     * Test seam: runs between the unlocked snapshot and the row locks, i.e. the
     * window in which a concurrent request could change the advance.
     */
    protected function afterSnapshot(): void
    {
    }

    /** @return array{header_id: int|null, months: array<int, string>, future_ids: array<int, int>} */
    private function snapshotAdvance(int $originRefrendId): array
    {
        $header = ScholarshipAdvancePayment::where('origin_refrend_id', $originRefrendId)->first();
        $months = $header === null
            ? collect()
            : ScholarshipAdvancePaymentMonth::where('advance_payment_id', $header->id)->orderBy('id')->get();

        return [
            'header_id'  => $header?->id,
            'months'     => $months->mapWithKeys(fn ($m) => [$m->id => (string) $m->status])->all(),
            'future_ids' => $months->pluck('refrend_id')->filter()->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
    }

    /**
     * @param array{header_id: int|null, months: array<int, string>, future_ids: array<int, int>} $snapshot
     * @param Collection<int, ScholarshipAdvancePaymentMonth> $months
     * @param Collection<int, ScholarshipRefrend> $futures
     */
    private function matchesSnapshot(
        array $snapshot,
        ?ScholarshipAdvancePayment $header,
        Collection $months,
        Collection $futures
    ): bool {
        if ($header === null || $header->id !== $snapshot['header_id']) {
            return false;
        }

        $current = $months->mapWithKeys(fn ($m) => [$m->id => (string) $m->status])->all();
        ksort($current);
        $expected = $snapshot['months'];
        ksort($expected);

        return $current === $expected && $futures->count() === count($snapshot['future_ids']);
    }

    /** @param Collection<int, ScholarshipAdvancePaymentMonth> $months */
    private function headerSnapshot(ScholarshipAdvancePayment $header, Collection $months): array
    {
        return [
            'id'                  => $header->id,
            'status'              => $header->status,
            'origin_period_year'  => $header->origin_period_year,
            'origin_period_month' => $header->origin_period_month,
            'months_count'        => $header->months_count,
            'total_amount'        => (string) $header->total_amount,
            'cause'               => $header->cause,
            'notes'               => $header->notes,
            'created_by_id'       => $header->created_by_id,
            'created_at'          => $header->created_at?->toDateTimeString(),
            'updated_at'          => $header->updated_at?->toDateTimeString(),
            'months'              => $months->map(fn (ScholarshipAdvancePaymentMonth $m) => [
                'id'           => $m->id,
                'period_year'  => $m->period_year,
                'period_month' => $m->period_month,
                'amount'       => (string) $m->amount,
                'refrend_id'   => $m->refrend_id,
                'status'       => $m->status,
            ])->values()->all(),
        ];
    }
}
