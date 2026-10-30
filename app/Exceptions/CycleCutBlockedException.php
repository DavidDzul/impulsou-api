<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;
use Illuminate\Http\JsonResponse;

/**
 * sdd/egresado-baja-definitiva, amendment-2 / amendment-4 (guard #1 of 4,
 * evaluated second — after CYCLE_ALREADY_CLOSED). Thrown by
 * CloseScholarshipCycleAction when at least one period AFTER the cut period
 * is already settled in a way the cut cannot silently override: a later
 * refrend that is already locked/batched (reason PAID), or a later refrend that already
 * has active applied ScholarshipWithholdingPayment rows (reason
 * ABONOS_APPLIED). A PENDING advance month no longer blocks (S5: it is
 * cancelled instead). No partial state — ALL blocking months are listed in one
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

    /** 409 body used by the user deactivation endpoints (sdd/retenciones-pendientes). */
    public function toConflictResponse(): JsonResponse
    {
        return response()->json([
            'res'  => false,
            'msg'  => $this->getMessage(),
            'code' => self::CODE,
            'data' => $this->payload(),
        ], 409);
    }
}
