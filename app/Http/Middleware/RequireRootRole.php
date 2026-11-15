<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * Restricts a route to the ROOT role, independent of any permission rows.
 */
class RequireRootRole
{
    public function handle($request, Closure $next, $guard = null)
    {
        $user = Auth::guard($guard)->user();

        if (!$user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (!$user->hasRole('ROOT')) {
            throw UnauthorizedException::forRoles(['ROOT']);
        }

        return $next($request);
    }
}
