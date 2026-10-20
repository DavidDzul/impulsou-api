<?php

namespace App\Support\Scholarship;

use App\Exceptions\UserTypeLockedException;
use App\Models\User;

/**
 * sdd/egresar-boton-corte-ciclo (REQ-PM-2). A user with a ScholarshipProfile
 * cannot have its user_type changed through the user update endpoints; the
 * only manual egreso is the Egresado payment situation.
 */
final class UserTypeChangeGuard
{
    /**
     * @param array<string, mixed> $validated validated request data
     *
     * @throws UserTypeLockedException
     */
    public static function assertAllowed(User $user, array $validated): void
    {
        if (!array_key_exists('user_type', $validated)) {
            return;
        }

        if ($validated['user_type'] === $user->user_type) {
            return;
        }

        if ($user->scholarshipProfile()->exists()) {
            throw new UserTypeLockedException();
        }
    }
}
