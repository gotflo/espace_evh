<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temps reel : une modification reussie (publication, validation, reponse...) efface les
 * signatures du « pouls » gardees 10 s en cache. Le prochain passage du pouls, chez l'auteur
 * comme chez les autres, voit donc le changement tout de suite et les ecrans concernes se
 * mettent a jour en silence. Les signaux techniques frequents (lecture des videos, appareils,
 * notifications lues) ne comptent pas : ils ne changent rien a l'affichage des autres.
 */
class RefreshPulse
{
    private const IGNORED = ['api/me/*/progress', 'api/me/*/*/progress', 'api/me/notifications*', 'api/me/push*', 'api/monitor/*', 'api/auth/*', 'api/me/pulse', 'api/me/announcements/read'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && $response->getStatusCode() < 400
            && ! Str::is(self::IGNORED, $request->path())) {
            Cache::forget('pulse:versions');
        }

        return $response;
    }
}
