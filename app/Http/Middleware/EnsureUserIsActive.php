<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Rejects authenticated users whose account was deactivated, so a still-valid token
 * stops working on the very next request. 401 (not 403): panels treat it as logged out.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && !$user->active) {
            return response()->json([
                'res' => false,
                'msg' => 'Tu cuenta está desactivada.',
            ], 401);
        }

        return $next($request);
    }
}
