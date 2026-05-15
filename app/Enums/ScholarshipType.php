<?php

namespace App\Enums;

enum ScholarshipType: string
{
    case IU = 'IU';
    case TELMEX = 'TELMEX';

    public function label(): string
    {
        return match($this) {
            self::IU     => 'IU',
            self::TELMEX => 'TELMEX',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
