<?php

namespace App\Services\Scholarship;

use App\Support\Scholarship\RefrendConceptSplit;
use App\Support\Scholarship\RefrendNetComponents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * sdd/archivo-pago-beca-apoyo (R5, D4-D6): splits each row's carryover
 * (amount_pending_from_previous + advance_payment_amount) into Beca / Apoyo by
 * where the money came from in the ledgers.
 *
 *  - Advance: every non-CANCELLED month of the row's advance payment is weighed
 *    by the month split of its child refrend.
 *  - Pending: every non-voided withholding payment applied to the row is
 *    weighed by the month split of the withholding's origin refrend.
 *  - An item whose refrend is missing or weightless uses the current row's
 *    weights (RefrendConceptSplit::fallbackWeights); one level, no recursion.
 *  - Each part is rescaled to its column (scaleTo), so the result always
 *    equals the columns and the residual lands in Beca.
 *
 * Exactly 2 queries per call (0 for an empty batch), whatever the row count.
 * Read-only; DB::table so no model hydration.
 */
class CarryoverConceptResolver
{
    private const ADVANCE_CANCELLED = 'CANCELLED';

    /**
     * @param Collection<int,object> $rows refrend rows carrying RefrendConceptSplit::COLUMNS;
     *        the id is read from `refrend_id` (PaymentBatchService) or `id`.
     * @return array<int,RefrendConceptSplit> carryover split per refrend id (total == pending + advance)
     */
    public function forRows(Collection $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) ($row->refrend_id ?? $row->id)] = $row;
        }
        if ($byId === []) {
            return [];
        }

        $ids     = array_keys($byId);
        $advance = $this->advanceItems($ids);
        $pending = $this->pendingItems($ids);

        $result = [];
        foreach ($byId as $id => $row) {
            $fallback = RefrendConceptSplit::fallbackWeights($row);

            $advanceSplit = $this->ledgerSplit($advance[$id] ?? [], $fallback)
                ->scaleTo(RefrendNetComponents::toCents($row->advance_payment_amount ?? 0), $fallback);
            $pendingSplit = $this->ledgerSplit($pending[$id] ?? [], $fallback)
                ->scaleTo(RefrendNetComponents::toCents($row->amount_pending_from_previous ?? 0), $fallback);

            $result[$id] = RefrendConceptSplit::of(
                $advanceSplit->becaCents() + $pendingSplit->becaCents(),
                $advanceSplit->apoyoCents() + $pendingSplit->apoyoCents(),
            );
        }

        return $result;
    }

    /**
     * @param array<int,array{amount:int,basis:int,ref:?object}> $items
     */
    private function ledgerSplit(array $items, RefrendConceptSplit $fallback): RefrendConceptSplit
    {
        $beca  = 0;
        $apoyo = 0;

        foreach ($items as $item) {
            $weights = $item['ref'] !== null && RefrendConceptSplit::hasWeights($item['ref'])
                ? RefrendConceptSplit::month($item['ref'], $item['basis'])
                : RefrendConceptSplit::zero();

            $split  = RefrendConceptSplit::proportional($item['amount'], $weights, $fallback);
            $beca  += $split->becaCents();
            $apoyo += $split->apoyoCents();
        }

        return RefrendConceptSplit::of($beca, $apoyo);
    }

    /** Query 1. @return array<int,list<array{amount:int,basis:int,ref:?object}>> by row id */
    private function advanceItems(array $ids): array
    {
        $records = DB::table('scholarship_advance_payment_months as apm')
            ->join('scholarship_advance_payments as ap', 'ap.id', '=', 'apm.advance_payment_id')
            ->leftJoin('scholarship_refrends as c', 'c.id', '=', 'apm.refrend_id')
            ->whereIn('ap.origin_refrend_id', $ids)
            ->where('apm.status', '!=', self::ADVANCE_CANCELLED)
            ->select(array_merge(
                ['ap.origin_refrend_id as row_id', 'apm.amount as item_amount'],
                $this->refrendColumns('c', 'r_')
            ))
            ->get();

        $items = [];
        foreach ($records as $record) {
            $amount = RefrendNetComponents::toCents($record->item_amount);
            $items[(int) $record->row_id][] = ['amount' => $amount, 'basis' => $amount, 'ref' => $this->refrendFrom($record)];
        }

        return $items;
    }

    /** Query 2. @return array<int,list<array{amount:int,basis:int,ref:?object}>> by row id */
    private function pendingItems(array $ids): array
    {
        $records = DB::table('scholarship_withholding_payments as swp')
            ->join('scholarship_withholdings as w', 'w.id', '=', 'swp.withholding_id')
            ->leftJoin('scholarship_refrends as o', 'o.id', '=', 'w.origin_refrend_id')
            ->whereIn('swp.applied_refrend_id', $ids)
            ->where('swp.is_voided', false)
            ->select(array_merge(
                ['swp.applied_refrend_id as row_id', 'swp.amount as item_amount', 'w.withheld_amount as item_basis'],
                $this->refrendColumns('o', 'r_')
            ))
            ->get();

        $items = [];
        foreach ($records as $record) {
            $items[(int) $record->row_id][] = [
                'amount' => RefrendNetComponents::toCents($record->item_amount),
                'basis'  => RefrendNetComponents::toCents($record->item_basis),
                'ref'    => $this->refrendFrom($record),
            ];
        }

        return $items;
    }

    /** @return list<string> */
    private function refrendColumns(string $table, string $prefix): array
    {
        return array_map(
            static fn (string $column): string => "{$table}.{$column} as {$prefix}{$column}",
            RefrendConceptSplit::COLUMNS
        );
    }

    /** The joined refrend as a plain row, or null when the LEFT JOIN found nothing. */
    private function refrendFrom(object $record): ?object
    {
        if ($record->r_id === null) {
            return null;
        }

        $refrend = [];
        foreach (RefrendConceptSplit::COLUMNS as $column) {
            $refrend[$column] = $record->{'r_' . $column};
        }

        return (object) $refrend;
    }
}
