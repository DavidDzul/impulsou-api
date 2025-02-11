<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CheckBusinessAgreement
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user->agreement || Carbon::now()->gt($user->agreement->end_date)) {
            return response()->json([
                'res' => false,
                'msg' => 'El convenio de la empresa ha expirado. Contacta al personal para renovarlo.',
            ], 403);
        }

        return $next($request);
    }
}
