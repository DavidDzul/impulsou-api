<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/catalogo-bancos R7: guard failures shared by every manually-paid
 * channel (cheque and other-bank transfer). The shared factories use
 * `new static`, so ChequePaymentException keeps raising its own type with
 * the exact same codes, messages and HTTP statuses.
 */
class ManualPaymentException extends \DomainException implements CarriesErrorPayload
{
    protected function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
        private readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public static function alreadyPaid(): static
    {
        return new static('El pago de este refrend ya fue realizado.', 'ALREADY_PAID', 409);
    }

    /**
     * @param array<int, array{code: string, message: string}> $reasons
     */
    public static function notPayable(array $reasons): static
    {
        $message = 'El refrendo no se puede pagar: ' . implode('; ', array_column($reasons, 'message'));

        return new static($message, 'NOT_PAYABLE', 422, ['reasons' => $reasons]);
    }

    public static function conceptSplitChanged(): static
    {
        return new static(
            'El desglose Beca/Apoyo cambió; actualiza la pantalla e inténtalo de nuevo.',
            'CONCEPT_SPLIT_CHANGED',
            409
        );
    }

    public static function cycleClosed(): static
    {
        return new static('El ciclo del becario está cerrado.', 'CYCLE_CLOSED', 409);
    }

    public static function telmexRepaymentExists(): static
    {
        return new static('El mes tiene reembolsos de Telmex registrados.', 'TELMEX_REPAYMENT_EXISTS', 409);
    }

    public static function notManualTransferMethod(): static
    {
        return new static(
            'El becario no tiene configurada una transferencia a otro banco.',
            'NOT_MANUAL_TRANSFER_METHOD',
            422
        );
    }

    public static function notManualTransferPaid(): static
    {
        return new static('El refrendo no está pagado con transferencia a otro banco.', 'NOT_MANUAL_TRANSFER_PAID', 422);
    }

    public static function transferReferenceTaken(string $concept): static
    {
        return new static(
            'Esa referencia de transferencia ya fue registrada.',
            'TRANSFER_REFERENCE_TAKEN',
            422,
            ['concept' => $concept]
        );
    }

    public static function transferLogMissing(): static
    {
        return new static(
            'No se encontró un registro válido del estado previo al pago por transferencia.',
            'TRANSFER_LOG_MISSING',
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
