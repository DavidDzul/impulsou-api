<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * sdd/egresar-boton-corte-ciclo (REQ-PM-2) - thrown by UserTypeChangeGuard
 * when an update tries to change the user_type of a scholarship holder. The
 * only manual egreso is the Egresado payment situation. Extends
 * \DomainException like the other scholarship guards.
 */
class UserTypeLockedException extends \DomainException
{
    public const CODE = 'USER_TYPE_LOCKED';

    public const MESSAGE = 'Para egresar a un becario usa la situación Egresado en su refrendo.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }

    /** 422 body shared by UserController and GraduateController. */
    public function toResponse(): JsonResponse
    {
        return response()->json([
            'res'     => false,
            'msg'     => $this->getMessage(),
            'message' => $this->getMessage(),
            'code'    => self::CODE,
        ], 422);
    }
}
