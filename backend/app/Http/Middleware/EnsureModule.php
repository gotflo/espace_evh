<?php

namespace App\Http\Middleware;

use App\Support\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module desactive dans les reglages de la plateforme : ses adresses sont fermees (lecture et ecriture).
 * Les donnees restent intactes et reviennent a la reactivation.
 */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! PlatformSettings::module($module)) {
            return response()->json(['message' => "Ce module n'est pas activé sur la plateforme.", 'module' => $module], 403);
        }

        return $next($request);
    }
}
