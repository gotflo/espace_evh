<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte bloque depuis la console : plus aucune action possible, meme avec une session
 * ouverte avant le blocage (les jetons sont aussi revoques au moment du blocage).
 */
class EnsureNotBlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof \App\Models\User && $user->blocked_at) {
            $token = $user->currentAccessToken();
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
                $token->delete();
            }
            abort(403, 'Ce compte est suspendu. Contactez un responsable de l\'église.');
        }

        return $next($request);
    }
}
