<?php

namespace App\Enums;

/**
 * sdd/egresado-baja-definitiva, design D1 — value of
 * scholarship_profiles.cycle_closure_reason, set alongside
 * cycle_closed_period as the stop marker for a terminal cycle cut.
 */
enum CycleClosureReason: string
{
    case EGRESADO         = 'EGRESADO';
    case BAJA_DEFINITIVA  = 'BAJA_DEFINITIVA';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
