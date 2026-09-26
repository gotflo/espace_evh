<?php

namespace App\Http\Middleware;

use App\Services\ActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derniere utilisation de l'application (last_seen_at, au plus une ecriture toutes les
 * 10 minutes) et reactivation immediate d'un membre inactif qui revient.
 */
class TrackActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user && $response->getStatusCode() < 400
            && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(10)) || $user->activity_status === 'inactive')) {
            ActivityService::touch($user, true, 'usage');
        }

        return $response;
    }
}
