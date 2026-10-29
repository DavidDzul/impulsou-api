<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/revertir-pago-adelantado, design "Exception": typed guard failures of
 * RecordAdvancePaymentAction (chain guard) and RevertAdvancePaymentAction.
 * Extends \DomainException and implements CarriesErrorPayload so the
 * controller maps it to the {res, msg, code, data} envelope with the HTTP
 * status the guard requires (409 for a closed cycle, 404 for a missing
 * refrend, 422 otherwise).
 */
class AdvancePaymentException extends \DomainException implements CarriesErrorPayload
{
    public const NOT_FOUND                    = 'NOT_FOUND';
    public const ADVANCE_NOT_FOUND            = 'ADVANCE_NOT_FOUND';
    public const ADVANCE_ORIGIN_PAID          = 'ADVANCE_ORIGIN_PAID';
    public const ADVANCE_ORIGIN_LOCKED        = 'ADVANCE_ORIGIN_LOCKED';
    public const ADVANCE_CYCLE_CLOSED         = 'ADVANCE_CYCLE_CLOSED';
    public const ADVANCE_MONTH_SETTLED        = 'ADVANCE_MONTH_SETTLED';
    public const ADVANCE_FUTURE_TELMEX_COVERED = 'ADVANCE_FUTURE_TELMEX_COVERED';
    public const ADVANCE_FUTURE_NOT_PRISTINE  = 'ADVANCE_FUTURE_NOT_PRISTINE';
    public const ADVANCE_STATE_CHANGED        = 'ADVANCE_STATE_CHANGED';
    public const ADVANCE_CHAINED_FORBIDDEN    = 'ADVANCE_CHAINED_FORBIDDEN';

    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        private readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('El refrendo no existe.', self::NOT_FOUND, 404);
    }

    public static function advanceNotFound(): self
    {
        return new self('Este refrendo no tiene un pago adelantado registrado.', self::ADVANCE_NOT_FOUND, 422);
    }

    public static function originPaid(): self
    {
        return new self(
            'El refrendo de origen ya fue pagado; no se puede revertir el pago adelantado.',
            self::ADVANCE_ORIGIN_PAID,
            422
        );
    }

    public static function originLocked(): self
    {
        return new self(
            'El refrendo de origen está cerrado o bloqueado; no se puede revertir el pago adelantado.',
            self::ADVANCE_ORIGIN_LOCKED,
            422
        );
    }

    public static function cycleClosed(): self
    {
        return new self(
            'El ciclo del becario ya fue cerrado; no se puede revertir el pago adelantado.',
            self::ADVANCE_CYCLE_CLOSED,
            409
        );
    }

    public static function monthSettled(): self
    {
        return new self(
            'Alguno de los meses adelantados ya fue resuelto; no se puede revertir el pago adelantado.',
            self::ADVANCE_MONTH_SETTLED,
            422
        );
    }

    public static function futureTelmexCovered(): self
    {
        return new self(
            'Alguno de los refrendos adelantados está cubierto por Telmex; no se puede revertir el pago adelantado.',
            self::ADVANCE_FUTURE_TELMEX_COVERED,
            422
        );
    }

    public static function futureNotPristine(): self
    {
        return new self(
            'Alguno de los refrendos adelantados ya tiene movimientos; no se puede revertir el pago adelantado.',
            self::ADVANCE_FUTURE_NOT_PRISTINE,
            422
        );
    }

    public static function stateChanged(): self
    {
        return new self(
            'El pago adelantado cambió mientras se procesaba la solicitud. Actualiza la pantalla e inténtalo de nuevo.',
            self::ADVANCE_STATE_CHANGED,
            422
        );
    }

    public static function chainedForbidden(): self
    {
        return new self(
            'No se puede registrar un pago adelantado sobre un refrendo generado por otro pago adelantado.',
            self::ADVANCE_CHAINED_FORBIDDEN,
            422
        );
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function payload(): array
    {
        return $this->data;
    }
}
