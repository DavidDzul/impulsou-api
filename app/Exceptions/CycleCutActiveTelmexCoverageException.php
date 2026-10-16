<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/egresado-baja-definitiva, amendment-3 (overrides design D6's "do not
 * auto-end coverage" note with an explicit block instead — guard #2 of 4,
 * evaluated third). Thrown by CloseScholarshipCycleAction when the becario
 * has a TelmexCoverage row in status ACTIVA. EN_COBRO/LIQUIDADA/CANCELADA do
 * NOT block — repayment tracking continues after the cut. No automatic
 * coverage changes are made; staff must cancel the coverage in Becas Telmex
 * (administration-panel) and retry.
 */
class CycleCutActiveTelmexCoverageException extends \DomainException implements CarriesErrorPayload
{
    public const CODE = 'CYCLE_CUT_ACTIVE_TELMEX_COVERAGE';

    public function __construct(
        string $message,
        private int $coverageId,
        private string $status,
        private string $startPeriod
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function payload(): array
    {
        return [
            'coverage_id'  => $this->coverageId,
            'status'       => $this->status,
            'start_period' => $this->startPeriod,
        ];
    }
}
