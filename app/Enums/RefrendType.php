<?php

namespace App\Enums;

enum RefrendType: string
{
    case NORMAL    = 'NORMAL';
    case RETENCION = 'RETENCION';
    case REEMBOLSO = 'REEMBOLSO';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
