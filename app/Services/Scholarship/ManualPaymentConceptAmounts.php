<?php

namespace App\Services\Scholarship;

use App\Support\Scholarship\PaymentAmountFloor;
use App\Support\Scholarship\RefrendConceptSplit;
use App\Support\Scholarship\RefrendNetComponents;
use App\Support\Scholarship\RefrendPayableTotal;
use Illuminate\Support\Facades\DB;

/**
 * sdd/bank-file-split-other-banks-cheques: the Beca / Apoyo amounts a cheque or
 * manual transfer of ONE refrend pays, computed with the same pipeline as
 * PaymentBatchService::conceptSplit() (raw total, bank-file floor, carryover
 * ledger split, RefrendConceptSplit::forRow) so the figures match what rows()
 * displays as concept_split. Read-only.
 */
class ManualPaymentConceptAmounts
{
    public function __construct(private CarryoverConceptResolver $carryover)
    {
    }

    /**
     * Only the concepts with an amount > 0 are returned, as decimal strings
     * ('beca' then 'apoyo'); their sum equals the displayed total_to_pay to
     * the cent. An empty array means there is nothing to pay (the caller
     * rejects it with NOTHING_TO_PAY).
     *
     * @return array<string, string>
     */
    public function forRefrend(int $refrendId): array
    {
        $row = DB::table('scholarship_refrends')
            ->where('id', $refrendId)
            ->first(RefrendConceptSplit::COLUMNS);

        if ($row === null) {
            return [];
        }

        $raw       = RefrendPayableTotal::of($row);
        $displayed = PaymentAmountFloor::apply($row->snapshot_scholarship_type, $raw);

        $split = RefrendConceptSplit::forRow(
            $row,
            RefrendNetComponents::toCents($raw),
            RefrendNetComponents::toCents($displayed),
            $this->carryover->forRows(collect([$row]))[$refrendId] ?? RefrendConceptSplit::zero()
        );

        $amounts = $split->toArray();

        return array_filter([
            'beca'  => $split->becaCents() > 0 ? $amounts['beca'] : null,
            'apoyo' => $split->apoyoCents() > 0 ? $amounts['apoyo'] : null,
        ], fn (?string $amount) => $amount !== null);
    }
}
