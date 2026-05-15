<?php

namespace App\Enums;

enum RefrendStatus: string
{
    case DRAFT            = 'DRAFT';
    case ATENCION_REVIEW  = 'ATENCION_REVIEW';
    case PEDAGOGIA_REVIEW = 'PEDAGOGIA_REVIEW';
    case AUTHORIZED       = 'AUTHORIZED';
    case PAID             = 'PAID';
    case WITHHELD         = 'WITHHELD';
    case CANCELLED        = 'CANCELLED';

    public function isLocked(): bool
    {
        return in_array($this, [self::PAID, self::AUTHORIZED]);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
