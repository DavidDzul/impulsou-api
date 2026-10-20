<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * sdd/generaciones-activas-refrendos (R3) - thrown when an active generation
 * is being deactivated while it still has becarios with pending payments.
 * Same 422 shape as UserTypeLockedException.
 */
class GenerationHasPendingBecariosException extends \DomainException
{
    public const CODE = 'GENERATION_HAS_PENDING_BECARIOS';

    private int $pendingCount;

    public function __construct(int $pendingCount)
    {
        $this->pendingCount = $pendingCount;

        parent::__construct(sprintf(
            'No se puede desactivar la generación: tiene %d becarios con pagos pendientes (retícula o meses de gracia vigentes).',
            $pendingCount
        ));
    }

    public function pendingCount(): int
    {
        return $this->pendingCount;
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'res'                    => false,
            'msg'                    => $this->getMessage(),
            'message'                => $this->getMessage(),
            'code'                   => self::CODE,
            'pending_becarios_count'  => $this->pendingCount,
        ], 422);
    }
}
