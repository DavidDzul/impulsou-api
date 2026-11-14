<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * sdd/clases-filtro-periodo-asignacion-manual (R2.4) - thrown when a manual
 * class assignment includes new users that are not active scholarship holders
 * of the class campus and generation. Same 422 shape as
 * GenerationHasPendingBecariosException.
 */
class ClassUsersNotEligibleException extends \DomainException
{
    public const CODE = 'USERS_NOT_ELIGIBLE_FOR_CLASS';

    /** @var int[] */
    private array $ineligibleUserIds;

    public function __construct(array $ineligibleUserIds)
    {
        $this->ineligibleUserIds = array_values($ineligibleUserIds);

        parent::__construct(sprintf(
            'No se pueden asignar %d usuario(s): solo becarios activos de la sede y generación de la clase.',
            count($this->ineligibleUserIds)
        ));
    }

    public function ineligibleUserIds(): array
    {
        return $this->ineligibleUserIds;
    }

    /** Laravel calls render() on exceptions that define it. */
    public function render($request = null): JsonResponse
    {
        return $this->toResponse();
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'res'                => false,
            'msg'                => $this->getMessage(),
            'message'            => $this->getMessage(),
            'code'               => self::CODE,
            'ineligible_user_ids' => $this->ineligibleUserIds,
        ], 422);
    }
}
