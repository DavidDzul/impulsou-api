<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * Gate for psicol-panel permissions (PS_*) with a ROOT bypass.
 *
 * ROOT passes when the requested list contains AT LEAST ONE "PS_" permission, so
 * "ROOT has access to everything in psicol" does not depend on seeder/DB drift,
 * including mixed lists such as PS_READ_USERS|ADM_READ_USERS. Lists made only of
 * ADM_* permissions never bypass (ROOT keeps no administration-panel access).
 * Everyone else is delegated to CheckPermission unchanged (same 401/403 contract).
 *
 * Separator: ONLY "|" separates permissions. Laravel splits middleware
 * parameters on commas, so "ps_permission:PS_A,PS_B" is read as permission
 * PS_A with guard PS_B (an invalid guard) and fails; never use a comma.
 */
class CheckPsPermission
{
    private const PS_PREFIX = 'PS_';

    public function handle($request, Closure $next, $permission, $guard = null)
    {
        $user = Auth::guard($guard)->user();

        if (!$user) {
            throw UnauthorizedException::notLoggedIn();
        }

        $permissions = is_array($permission) ? $permission : explode('|', $permission);

        if ($user->hasRole('ROOT') && $this->containsPsPermission($permissions)) {
            return $next($request);
        }

        return app(CheckPermission::class)->handle($request, $next, $permission, $guard);
    }

    private function containsPsPermission(array $permissions): bool
    {
        foreach ($permissions as $name) {
            if (str_starts_with($name, self::PS_PREFIX)) {
                return true;
            }
        }

        return false;
    }
}
