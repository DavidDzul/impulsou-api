<?php

namespace App\Enums;

enum DocumentType: string
{
    case CONSTANCIA_ESTUDIOS = 'CONSTANCIA_ESTUDIOS';
    case COMPROBANTE_PAGO          = 'COMPROBANTE_PAGO';
    case JUSTIFICANTE_MEDICO       = 'JUSTIFICANTE_MEDICO';
    case OTRO                      = 'OTRO';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
