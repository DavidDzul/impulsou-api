<?php

namespace App\Support\Scholarship;

use App\Enums\BankFileConcept;

/**
 * sdd/archivo-pago-beca-apoyo (R6, R9): turns PaymentBatchService::paidRows()
 * output into the rows of ONE concept file. The concept amount replaces
 * `total_to_pay` so the (unchanged) serializer prints it; rows with nothing to
 * disperse for the concept are dropped, and order is preserved. The serializer
 * numbers lines per call, so every file restarts at consecutivo 1.
 *
 * Pure: no DB.
 */
final class BankFileConceptRows
{
    /**
     * @param array<int, array<string, mixed>> $rows paidRows() rows carrying beca_amount / apoyo_amount
     * @return array<int, array<string, mixed>>
     */
    public static function for(array $rows, BankFileConcept $concept): array
    {
        $key    = $concept->amountKey();
        $mapped = [];

        foreach ($rows as $row) {
            $amount = (string) ($row[$key] ?? '0.00');

            if (RefrendNetComponents::toCents($amount) <= 0) {
                continue;
            }

            $row['total_to_pay'] = $amount;
            $mapped[]            = $row;
        }

        return $mapped;
    }
}
