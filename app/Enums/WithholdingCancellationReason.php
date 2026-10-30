<?php

namespace App\Enums;

/**
 * sdd/retenciones-pendientes — value of
 * scholarship_withholdings.cancellation_reason: why a retention ledger row was
 * moved to CANCELLED. Legacy CANCELLED rows keep NULL (no backfill).
 *
 * Values must stay within the varchar(20) column.
 */
enum WithholdingCancellationReason: string
{
    case CYCLE_CUT        = 'CYCLE_CUT';
    case EGRESO_RETICULA  = 'EGRESO_RETICULA';
    case USER_DEACTIVATED = 'USER_DEACTIVATED';
    case RECLASSIFIED     = 'RECLASSIFIED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
