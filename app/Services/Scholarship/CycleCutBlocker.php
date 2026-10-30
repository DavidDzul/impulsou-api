<?php

namespace App\Services\Scholarship;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/revertir-pago-adelantado, design A5 (S5): one reason why a manual cycle
 * cut (BAJA_DEFINITIVA / EGRESADO) cannot proceed, returned by the inspect*()
 * methods of CloseScholarshipCycleAction. execute() throws the carried
 * exception of the first blocker (the SAME exception types as before the
 * refactor); the preview endpoint (S6) collects them all as {code, msg, data}.
 */
final class CycleCutBlocker
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $data,
        public readonly \DomainException $exception
    ) {
    }

    public static function fromException(\DomainException $exception, ?string $fallbackCode = null): self
    {
        $isPayload = $exception instanceof CarriesErrorPayload;

        return new self(
            $isPayload ? $exception->code() : (string) $fallbackCode,
            $exception->getMessage(),
            $isPayload ? $exception->payload() : [],
            $exception
        );
    }

    /** @return array{code: string, msg: string, data: array<string, mixed>} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'msg' => $this->message, 'data' => $this->data];
    }
}
