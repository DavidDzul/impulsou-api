<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * Drop-in replacement for \Spatie\Permission\Middleware\PermissionMiddleware.
 *
 * VERIFIED BLOCKER discovered during becarios-payment-config PR1b apply (NOT
 * anticipated by design obs #1583 D4, which assumed Spatie's own middleware
 * would work once the Kernel alias was registered):
 *
 * spatie/laravel-permission 6.10.1's PermissionRegistrar::getHydratedRoleCollection()
 * returns a plain Illuminate\Support\Collection (NOT an Eloquent Collection)
 * for a cached Permission's `roles` relation, with created_at/updated_at/
 * pivot attributes stripped (cache-size optimization). Spatie's own
 * HasPermissions::hasPermissionViaRole() -> HasRoles::hasRole(Collection)
 * compares roles via Collection::intersect(), which for a plain Support
 * Collection falls back to PHP's array_intersect(), which casts each
 * Eloquent model to string via Model::__toString() (toJson()). Because the
 * cached role's JSON differs from a freshly-queried role's JSON (missing
 * timestamps + pivot keys), the string comparison NEVER matches — so
 * canAny()/hasPermissionTo()/Spatie's own PermissionMiddleware ALWAYS
 * return false for permissions granted via a ROLE. Only permissions granted
 * DIRECTLY to a user work correctly, because hasDirectPermission() compares
 * by primary key via contains(), not via intersect().
 *
 * Reproduced directly: role IDs matched exactly (14 == 14) on both sides,
 * yet hasRole($cachedRolesCollection) returned false. getAllPermissions()
 * — which resolves the role->permissions path via a fresh, uncached
 * Eloquent query (loadMissing('roles', 'roles.permissions')) instead of the
 * cached collection — correctly returns the permission.
 *
 * This middleware keeps the exact same external contract as Spatie's
 * PermissionMiddleware (same alias name, same route syntax
 * `permission:NAME|NAME2`, same 401/403 exception types and messages so
 * error responses are indistinguishable to clients) but computes membership
 * via User::getAllPermissions(), bypassing the buggy cached-collection
 * comparison entirely.
 */
class CheckPermission
{
    public function handle($request, Closure $next, $permission, $guard = null)
    {
        $authGuard = Auth::guard($guard);
        $user = $authGuard->user();

        if (!$user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (!method_exists($user, 'getAllPermissions')) {
            throw UnauthorizedException::missingTraitHasRoles($user);
        }

        $permissions = is_array($permission) ? $permission : explode('|', $permission);

        $granted = $user->getAllPermissions()->pluck('name')->intersect($permissions)->isNotEmpty();

        if (!$granted) {
            throw UnauthorizedException::forPermissions($permissions);
        }

        return $next($request);
    }
}
