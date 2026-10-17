<?php

namespace App\Http\Middleware;

use App\Support\AutomationLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filet de securite des automatismes : si le cron de l'hebergement n'est pas (encore)
 * configure, l'activite de l'application lance elle-meme « app:tick », au plus une fois
 * toutes les 5 minutes et apres l'envoi de la reponse (aucun ralentissement visible).
 */
class TriggerAutomation
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Seulement si le cron ne tourne pas (sinon les automatismes passeraient deux fois), et un seul
        // passage a la fois (verrou commun avec le cron).
        if (config('services.automation.auto_tick') && ! AutomationLock::cronSeen() && Cache::add('automation:tick-lock', now()->timestamp, 300)) {
            defer(function () {
                ignore_user_abort(true);
                @set_time_limit(300);
                try {
                    AutomationLock::run(fn () => Artisan::call('app:tick'));
                } catch (\Throwable $e) {
                    Log::warning('Automatismes : echec', ['error' => $e->getMessage()]);
                }
            });
        }

        return $response;
    }
}
