<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\PulseController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temps reel : une modification reussie (publication, validation, reponse...) rend les signatures
 * du « pouls » perimees. Le prochain passage du pouls de l'auteur voit son changement tout de suite,
 * celui des autres au plus tard quelques secondes apres, et les ecrans concernes se mettent a jour
 * en silence. Les signaux techniques frequents (lecture des videos, appareils, notifications lues)
 * ne comptent pas : ils ne changent rien a l'affichage des autres.
 */
class RefreshPulse
{
    private const IGNORED = ['api/me/*/progress', 'api/me/*/*/progress', 'api/me/notifications*', 'api/me/push*', 'api/push/*', 'api/monitor/*', 'api/auth/*', 'api/me/pulse', 'api/me/announcements/read'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && $response->getStatusCode() < 400
            && ! Str::is(self::IGNORED, $request->path())) {
            PulseController::markChanged($request->user()?->id);
        }

        return $response;
    }
}
