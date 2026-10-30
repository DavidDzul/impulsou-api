<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/revertir-pago-adelantado, S8 (spec R22): a retícula edit would put the
 * egreso month on or before a PENDING advance month of the becario. Mapped by
 * the controller to 422 {res:false, msg, code:'RETICULA_ADVANCE_CONFLICT', data}.
 */
class ReticulaAdvanceConflictException extends \DomainException implements CarriesErrorPayload
{
    public const CODE = 'RETICULA_ADVANCE_CONFLICT';

    /**
     * @param array<int, array{period_year:int, period_month:int}> $months
     */
    public function __construct(
        private readonly int $egresoYear,
        private readonly int $egresoMonth,
        private readonly array $months
    ) {
        $labels = array_map(
            fn (array $m) => sprintf('%02d/%d', $m['period_month'], $m['period_year']),
            $months
        );

        $list = count($labels) > 1
            ? implode(', ', array_slice($labels, 0, -1)) . ' y ' . end($labels)
            : ($labels[0] ?? '');

        parent::__construct(sprintf(
            'Con esta retícula el egreso sería en %02d/%d, pero el becario tiene %s pagado por adelantado. Revierte primero el pago adelantado.',
            $egresoMonth,
            $egresoYear,
            $list
        ));
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function payload(): array
    {
        return [
            'egreso_period_year'  => $this->egresoYear,
            'egreso_period_month' => $this->egresoMonth,
            'months'              => $this->months,
        ];
    }
}
