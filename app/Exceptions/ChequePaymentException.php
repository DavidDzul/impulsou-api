<?php

namespace App\Exceptions;

/**
 * sdd/pago-cheque, design D13 — guard failures of MarkChequePaidAction and RevertChequePaymentAction.
 * The envelope plumbing and the guards shared with the other manual channel
 * (already paid, not payable, cycle closed, Telmex repayment) live in
 * ManualPaymentException; this class only adds the cheque-specific ones, so
 * the controller maps it to the {res, msg, code, data} envelope with the HTTP
 * status the guard requires (409 for a double submit, 422 otherwise).
 */
class ChequePaymentException extends ManualPaymentException
{
    public static function notChequeMethod(): self
    {
        return new self('El medio de pago del becario no es cheque.', 'NOT_CHEQUE_METHOD', 422);
    }

    public static function notChequePaid(): self
    {
        return new self('El refrendo no está pagado con cheque.', 'NOT_CHEQUE_PAID', 422);
    }

    public static function chequeReferenceTaken(string $concept): self
    {
        return new self(
            'Esa referencia de cheque ya fue registrada.',
            'CHEQUE_REFERENCE_TAKEN',
            422,
            ['concept' => $concept]
        );
    }

    public static function chequeLogMissing(): self
    {
        return new self(
            'No se encontró un registro válido del estado previo al pago por cheque.',
            'CHEQUE_LOG_MISSING',
            422
        );
    }
}
