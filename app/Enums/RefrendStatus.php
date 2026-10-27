<?php

namespace App\Enums;

enum RefrendStatus: string
{
    case DRAFT            = 'DRAFT';
    case PAID             = 'PAID';
    case WITHHELD         = 'WITHHELD';
    case CANCELLED        = 'CANCELLED';

    public function isLocked(): bool
    {
        return $this === self::PAID;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
