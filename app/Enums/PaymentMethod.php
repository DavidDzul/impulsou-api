<?php

namespace App\Enums;

/**
 * sdd/pago-cheque: how a becario is paid. TRANSFER is the default and
 * the only method that goes through payment batches / the bank file;
 * CHEQUE is paid manually, one refrend at a time.
 *
 * sdd/catalogo-bancos: MANUAL_TRANSFER is a transfer to a non-BBVA bank,
 * also paid manually. It is NEVER stored as a becario's payment_method
 * (see selectableValues()); it only exists as the paid_payment_method
 * snapshot and as the effective channel BatchBank::channelFor() derives.
 */
enum PaymentMethod: string
{
    case TRANSFER        = 'TRANSFER';
    case CHEQUE          = 'CHEQUE';
    case MANUAL_TRANSFER = 'MANUAL_TRANSFER';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Methods a client may store on a becario's payment data. */
    public static function selectableValues(): array
    {
        return [self::TRANSFER->value, self::CHEQUE->value];
    }

    /** Values of the methods paid manually, one refrend at a time. */
    public static function manualValues(): array
    {
        return [self::CHEQUE->value, self::MANUAL_TRANSFER->value];
    }

    /** True for methods that never go through a payment batch. */
    public function isManual(): bool
    {
        return $this === self::CHEQUE || $this === self::MANUAL_TRANSFER;
    }

    public function paidLogAction(): string
    {
        return $this === self::MANUAL_TRANSFER ? 'MANUAL_TRANSFER_PAID' : 'CHEQUE_PAID';
    }

    public function revertLogAction(): string
    {
        return $this === self::MANUAL_TRANSFER ? 'MANUAL_TRANSFER_REVERTED' : 'CHEQUE_PAYMENT_REVERTED';
    }
}
