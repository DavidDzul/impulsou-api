<?php

namespace App\Exceptions;

/**
 * Thrown by RecordPaymentSituationAction when a withholding payment's amount
 * no longer matches the ledger row's remaining_amount at the moment the
 * transaction lock is acquired (e.g. another payment or void mutated the
 * balance between the dialog loading and this submission reaching the
 * server). Carries a machine-readable CODE so the frontend can distinguish
 * this "stale balance, please refresh" case from a plain invalid-amount
 * rejection without string-matching the message.
 */
class WithholdingAmountMismatchException extends \DomainException
{
    public const CODE = 'WITHHOLDING_AMOUNT_MISMATCH';

    public function __construct(
        string $message = 'El saldo de esta retención cambió después de abrir el diálogo de pago. Cierre y vuelva a abrir el diálogo para ver el monto actualizado antes de reintentar.'
    ) {
        parent::__construct($message);
    }
}
