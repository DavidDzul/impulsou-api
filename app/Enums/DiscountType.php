<?php

namespace App\Enums;

enum DiscountType: string
{
    case RETARDOS          = 'RETARDOS';
    case FALTA_INJUSTIFICADA = 'FALTA_INJUSTIFICADA';
    case PROMEDIO_BAJO     = 'PROMEDIO_BAJO';
    case DOCUMENTOS        = 'DOCUMENTOS';
    case RECAUDACION       = 'RECAUDACION';
    case OTRO              = 'OTRO';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
