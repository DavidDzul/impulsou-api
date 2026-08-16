<?php

namespace App\Services\Scholarship;

use App\Enums\RefrendStatus;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the retention ledger from historical WITHHELD refrends and
 * reproduces the net pending debt that
 * GenerateMonthlyRefrendsService::calculatePendingCarryover() (removed in
 * PR3) used to compute on the fly, as synthetic
 * scholarship_withholding_payments child rows.
 *
 * Extracted out of the migration class so this data-migration logic is
 * independently unit-testable against seeded data — Laravel migrations
 * running under RefreshDatabase always execute against an empty schema, so
 * exercising the FIFO/backfill behavior requires calling this service
 * directly after seeding historical rows.
 *
 * Must run after the scholarship_withholding_payments table (child) exists:
 * if ledger rows end up with paid_amount > 0 without matching child rows,
 * the first recomputePaidAmount() call would zero them out and resurrect
 * already-settled debt (see design §8.6).
 */
class BackfillWithholdingLedgerService
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->createLedgerRows();
            $this->applyHistoricalCoverageFifo();
            $this->zeroOutOpenPendingCarryover();
        });
    }

    /**
     * Reverses only the synthetic data this service itself inserted
     * (created_by_id IS NULL). Does NOT restore amount_pending_from_previous
     * on still-open refrends — that value isn't preserved anywhere, so
     * step 3 of run() is not reversible.
     */
    public function revertSynthetic(): void
    {
        DB::table('scholarship_withholding_payments')->whereNull('created_by_id')->delete();
        DB::table('scholarship_withholdings')->whereNull('created_by_id')->delete();
    }

    private function createLedgerRows(): void
    {
        ScholarshipRefrend::where(function ($q) {
            $q->where('resolution_type', 'RETENIDA')
                ->orWhere(function ($q2) {
                    // Pre-workflow escape hatch: WITHHELD rows recorded before
                    // resolution_type existed.
                    $q2->where('status', RefrendStatus::WITHHELD->value)
                        ->whereNull('resolution_type');
                });
        })->chunkById(200, function ($refrends) {
            foreach ($refrends as $refrend) {
                // NOT `$refrend->discount_amount ?: $refrend->base_amount`: the
                // decimal:2 cast makes a zero discount the string "0.00", which
                // is truthy in PHP (only "0" and "" are falsy strings) — that
                // would always keep 0 instead of falling back to base_amount.
                $discountAmount = (float) $refrend->discount_amount;
                $withheld       = $discountAmount > 0 ? $discountAmount : (float) $refrend->base_amount;
                if ($withheld <= 0) {
                    continue;
                }

                ScholarshipWithholding::updateOrCreate(
                    ['origin_refrend_id' => $refrend->id],
                    [
                        'user_id'         => $refrend->user_id,
                        'period_year'     => $refrend->period_year,
                        'period_month'    => $refrend->period_month,
                        'withheld_amount' => number_format($withheld, 2, '.', ''),
                        'paid_amount'     => '0.00',
                        'status'          => 'PENDING',
                        'cause'           => $refrend->resolution_cause,
                        'created_by_id'   => null,
                    ]
                );
            }
        });
    }

    /**
     * Reproduces, per user, the net pending debt the old
     * calculatePendingCarryover() computed: sums amount_pending_from_previous
     * of PAID/AUTHORIZED refrends (the "already covered" pool) and applies it
     * FIFO — oldest ledger row first — inserting a synthetic child payment
     * for each portion consumed, attributed to the closed refrend that
     * carried that amount.
     */
    private function applyHistoricalCoverageFifo(): void
    {
        $userIds = ScholarshipWithholding::query()->distinct()->pluck('user_id');

        foreach ($userIds as $userId) {
            $ledgerRows = ScholarshipWithholding::where('user_id', $userId)
                ->orderBy('period_year')
                ->orderBy('period_month')
                ->get();

            if ($ledgerRows->isEmpty()) {
                continue;
            }

            $remaining = [];
            foreach ($ledgerRows as $row) {
                $remaining[$row->id] = (float) $row->withheld_amount;
            }

            $closedRefrends = ScholarshipRefrend::where('user_id', $userId)
                ->whereIn('status', [RefrendStatus::PAID->value, RefrendStatus::AUTHORIZED->value])
                ->where('amount_pending_from_previous', '>', 0)
                ->orderBy('period_year')
                ->orderBy('period_month')
                ->get();

            $ledgerIndex     = 0;
            $ledgerRowsArray = $ledgerRows->all();

            foreach ($closedRefrends as $closedRefrend) {
                $toApply = (float) $closedRefrend->amount_pending_from_previous;

                while ($toApply > ScholarshipWithholding::SETTLEMENT_EPSILON && $ledgerIndex < count($ledgerRowsArray)) {
                    $row          = $ledgerRowsArray[$ledgerIndex];
                    $rowRemaining = $remaining[$row->id];

                    if ($rowRemaining <= ScholarshipWithholding::SETTLEMENT_EPSILON) {
                        $ledgerIndex++;
                        continue;
                    }

                    $apply = round(min($rowRemaining, $toApply), 2);

                    ScholarshipWithholdingPayment::create([
                        'withholding_id'     => $row->id,
                        'applied_refrend_id' => $closedRefrend->id,
                        'amount'             => number_format($apply, 2, '.', ''),
                        'created_by_id'      => null,
                    ]);

                    $remaining[$row->id] -= $apply;
                    $toApply             -= $apply;

                    if ($remaining[$row->id] <= ScholarshipWithholding::SETTLEMENT_EPSILON) {
                        $ledgerIndex++;
                    }
                }

                if ($ledgerIndex >= count($ledgerRowsArray)) {
                    break;
                }
            }

            foreach ($ledgerRows as $row) {
                $row->recomputePaidAmount();
            }
        }
    }

    /**
     * Refrends still open under the new semantics of amount_pending_from_previous
     * ("liquidated by THIS refrend") had their value autocalculated by the old
     * carryover method and never actually liquidated via the ledger — zeroing
     * it prevents total_to_pay from double-counting debt already represented
     * by ledger rows. Closed refrends keep their historical value.
     */
    private function zeroOutOpenPendingCarryover(): void
    {
        DB::table('scholarship_refrends')
            ->whereIn('workflow_status', ['DRAFT', 'CON_INCIDENCIA', 'LISTO_PARA_PAGO'])
            ->where('amount_pending_from_previous', '>', 0)
            ->update(['amount_pending_from_previous' => 0]);
    }
}
