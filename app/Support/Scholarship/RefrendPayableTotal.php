<?php

namespace App\Support\Scholarship;

/**
 * Single home of the UNFLOORED payable-total formula
 * (final_amount + amount_pending_from_previous + advance_payment_amount),
 * sdd/telmex-pendiente-lote design D4.
 *
 * CROSS-REFERENCE (sdd/pago-adelantado, design D6): the formula is mirrored
 * by ScholarshipRefrend::getTotalToPayAttribute() and
 * RefrendBulkQueryService::buildTable()'s row-assembly closure; keep the
 * three in lockstep. Callers that need the bank-file floor apply
 * PaymentAmountFloor on top of this value.
 *
 * Returns a decimal string because the driver yields strings on MySQL and
 * floats on SQLite (same trap documented in RefrendBulkQueryService).
 */
final class RefrendPayableTotal
{
    /**
     * @param object $row Duck-typed row (stdClass or Eloquent model);
     *                    missing/null pending and advance count as 0.
     */
    public static function of(object $row): string
    {
        return number_format(
            (float) $row->final_amount
            + (float) ($row->amount_pending_from_previous ?? 0)
            + (float) ($row->advance_payment_amount ?? 0),
            2,
            '.',
            ''
        );
    }
}
