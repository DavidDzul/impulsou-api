<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/pago-cheque, design D13 — guard failures of MarkChequePaidAction.
 * Extends \DomainException and implements CarriesErrorPayload so the
 * controller maps it to the {res, msg, code, data} envelope with the HTTP
 * status the guard requires (409 for a double submit, 422 otherwise).
 */
class ChequePaymentException extends \DomainException implements CarriesErrorPayload
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        private readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public static function alreadyPaid(): self
    {
        return new self('El pago de este refrend ya fue realizado.', 'ALREADY_PAID', 409);
    }

    public static function notChequeMethod(): self
    {
        return new self('El medio de pago del becario no es cheque.', 'NOT_CHEQUE_METHOD', 422);
    }

    /**
     * @param array<int, array{code: string, message: string}> $reasons
     */
    public static function notPayable(array $reasons): self
    {
        $message = 'El refrend no se puede pagar: ' . implode('; ', array_column($reasons, 'message'));

        return new self($message, 'NOT_PAYABLE', 422, ['reasons' => $reasons]);
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
