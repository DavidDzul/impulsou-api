<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/egresado-baja-definitiva, amendment-4 (overrides the original
 * acknowledge-flag design in amendment #1982 / design D8 — guard #3 of 4,
 * evaluated last). Thrown by CloseScholarshipCycleAction whenever the
 * becario has ANY PENDING ScholarshipWithholding row, in or out of the
 * 3-month payable window. There is no acknowledge flag and no FORFEITED
 * status in this change (both moved to the future "Retenciones pendientes"
 * SDD) — the cut is rejected outright until every pending retention is
 * resolved in its own view.
 */
class CycleCutPendingWithholdingsException extends \DomainException implements CarriesErrorPayload
{
    public const CODE = 'CYCLE_CUT_PENDING_WITHHOLDINGS';

    /** @param array<int, array{id:int,period_year:int,period_month:int,withheld_amount:string,paid_amount:string,remaining_amount:string,is_current_refrend:bool}> $withholdings */
    public function __construct(string $message, private array $withholdings)
    {
        parent::__construct($message);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function payload(): array
    {
        return ['withholdings' => $this->withholdings];
    }
}
