<?php

namespace App\Enums;

/**
 * sdd/pago-cheque: how a becario is paid. TRANSFER is the default and
 * the only method that goes through payment batches / the bank file;
 * CHEQUE is paid manually, one refrend at a time.
 */
enum PaymentMethod: string
{
    case TRANSFER = 'TRANSFER';
    case CHEQUE   = 'CHEQUE';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
