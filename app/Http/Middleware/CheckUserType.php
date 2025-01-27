<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckUserType
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next, $type)
    {
        // Verifica si el usuario tiene el tipo solicitado
        if ($request->user()->user_type !== $type) {
            // Si no tiene el tipo adecuado, retorna una respuesta no autorizada
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
