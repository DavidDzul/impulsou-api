<?php

namespace App\Exceptions;

/**
 * sdd/perfil-beca-montos-admin — thrown by GenerateMonthlyRefrendsService::
 * assertConfigSet() when a profile's config_set_at marker is NULL (the beca
 * amounts were never saved by Administración). Extends \DomainException so
 * every existing `catch (\DomainException)` call site (generateForUser's 422,
 * RecordAdvancePaymentAction's rollback) keeps matching. generateForPeriod()
 * classifies it as `skipped_config_pending` (expected skip, not an error).
 */
class ScholarshipConfigPendingException extends \DomainException
{
    public const CODE = 'SCHOLARSHIP_CONFIG_PENDING';

    public const MESSAGE = 'El becario no tiene montos de beca configurados por Administración.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
