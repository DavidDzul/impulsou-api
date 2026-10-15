<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/egresado-baja-definitiva, amendment-2 / amendment-4 (guard #1 of 4,
 * evaluated second — after CYCLE_ALREADY_CLOSED). Thrown by
 * CloseScholarshipCycleAction when at least one period AFTER the cut period
 * is already settled in a way the cut cannot silently override: a PENDING
 * ScholarshipAdvancePaymentMonth (reason ADVANCE_PAID), a later refrend that
 * is already locked/batched (reason PAID), or a later refrend that already
 * has active applied ScholarshipWithholdingPayment rows (reason
 * ABONOS_APPLIED). No partial state — ALL blocking months are listed in one
 * response so staff can resolve them together instead of resubmitting once
 * per month.
 */
class CycleCutBlockedException extends \DomainException implements CarriesErrorPayload
{
    public const CODE = 'CYCLE_CUT_BLOCKED';

    /** @param array<int, array{year:int,month:int,reason:string}> $blockingMonths */
    public function __construct(string $message, private array $blockingMonths)
    {
        parent::__construct($message);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function payload(): array
    {
        return ['blocking_months' => $this->blockingMonths];
    }
}
