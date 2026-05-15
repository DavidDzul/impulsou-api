<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case PENDING   = 'PENDING';
    case SUBMITTED = 'SUBMITTED';
    case ACCEPTED  = 'ACCEPTED';
    case REJECTED  = 'REJECTED';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
