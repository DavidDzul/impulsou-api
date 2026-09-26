<?php

namespace App\Exceptions;

/**
 * Thrown by RecordPaymentSituationAction when staff resolves an
 * advance-paid refrend with a non-zero final_amount (sdd/pago-adelantado,
 * design D4 — corrected 2026-09-25 per live user feedback: the risky case
 * needing a reason is paying something on an already-advance-paid month,
 * not the safe $0 case) without supplying advance_divergence_reason.
 * Carries a machine-readable CODE so the frontend can distinguish this
 * "needs a reason, please retry" case from a plain rejection without
 * string-matching the message.
 */
class AdvanceDivergenceRequiredException extends \DomainException
{
    public const CODE = 'ADVANCE_DIVERGENCE_REQUIRED';

    public function __construct(
        string $message = 'Este mes ya fue pagado por adelantado: indique el motivo del cambio de resolución.'
    ) {
        parent::__construct($message);
    }
}
