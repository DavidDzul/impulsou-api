<?php

namespace App\Enums;

/**
 * sdd/egresado-baja-definitiva, design D11 — value of
 * scholarship_withholdings.forfeited_reason. VENCIDA is reserved for a
 * future automatic-expiry rule (backlog, amendment #1982) and is not set by
 * any code in this change.
 */
enum WithholdingForfeitReason: string
{
    case BAJA_DEFINITIVA = 'BAJA_DEFINITIVA';
    case VENCIDA         = 'VENCIDA';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
