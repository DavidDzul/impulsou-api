<?php

namespace App\Exceptions;

/**
 * sdd/egresar-boton-corte-ciclo — thrown by GenerateMonthlyRefrendsService::
 * assertReticulaSet() when a profile has no reticula_start_date OR no
 * reticula_end_date. Without both dates the generation window is unbounded
 * (money risk), so no refrend may be created. Extends \DomainException so
 * every existing `catch (\DomainException)` call site (generateForUser's 422,
 * RecordAdvancePaymentAction's rollback) keeps matching. generateForPeriod()
 * classifies it as `skipped_reticula_missing` (expected skip, not an error).
 */
class ScholarshipReticulaMissingException extends \DomainException
{
    public const CODE = 'SCHOLARSHIP_RETICULA_MISSING';

    public const MESSAGE = 'El becario no tiene fechas de retícula cargadas. Cárgalas en la sección Retícula de su perfil.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
