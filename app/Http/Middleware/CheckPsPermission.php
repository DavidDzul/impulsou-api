<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * Gate for psicol-panel permissions (PS_*) with a ROOT bypass.
 *
 * ROOT passes only when EVERY requested permission starts with "PS_", so the
 * bypass is independent of seeder/DB drift but never leaks to ADM_* gates or
 * to mixed lists. Everyone else is delegated to CheckPermission unchanged
 * (same 401/403 contract).
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

        if ($user->hasRole('ROOT') && $this->allArePsPermissions($permissions)) {
            return $next($request);
        }

        return app(CheckPermission::class)->handle($request, $next, $permission, $guard);
    }

    private function allArePsPermissions(array $permissions): bool
    {
        foreach ($permissions as $name) {
            if (!str_starts_with($name, self::PS_PREFIX)) {
                return false;
            }
        }

        return $permissions !== [];
    }
}
