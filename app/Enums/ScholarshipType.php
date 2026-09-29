<?php

namespace App\Enums;

enum ScholarshipType: string
{
    case IU = 'IU';
    case TELMEX = 'TELMEX';
    case TELMEX_IU = 'TELMEX_IU';

    public function label(): string
    {
        return match($this) {
            self::IU        => 'IU',
            self::TELMEX    => 'TELMEX',
            self::TELMEX_IU => 'Telmex - IU',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
