<?php

namespace App\Http\Middleware;

use App\Services\Monitoring\Monitor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Supervision de l'API : identifiant de requete (en-tete X-Request-Id, repris dans les journaux)
 * et mesure de chaque requete, enregistree apres l'envoi de la reponse (aucun ralentissement).
 */
class MonitorRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }
        // Laravel cree une nouvelle instance pour terminate() : le debut est garde dans la requete.
        $request->attributes->set('monitor_started_at', defined('LARAVEL_START') && ! app()->runningUnitTests() ? LARAVEL_START : microtime(true));
        $id = Monitor::startRequest();

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $startedAt = $request->attributes->get('monitor_started_at');
        if (is_float($startedAt) && $request->getMethod() !== 'OPTIONS') {
            Monitor::recordRequest($request, $response, $startedAt);
        }
    }
}
