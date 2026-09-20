<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifie que l'utilisateur connecte possede une permission donnee.
 * Usage : ->middleware('permission:roles.assign')
 * Le super admin passe toujours (hasPermission le gere).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasPermission($permission)) {
            abort(403, 'Vous n\'avez pas la permission requise.');
        }

        return $next($request);
    }
}
