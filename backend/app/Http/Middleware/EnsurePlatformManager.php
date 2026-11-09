<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outils reserves au compte de gestion (l'administration de la plateforme) : aucun role ne les donne, pas meme
 * celui du Pasteur Resident. Usage : ->middleware('platform.manager')
 */
class EnsurePlatformManager
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isPlatformManager()) {
            abort(403, 'Cette page est réservée à l’administration de la plateforme.');
        }

        return $next($request);
    }
}
