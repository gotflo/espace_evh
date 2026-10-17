<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SecurityJournal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte de gestion principal :
 * - il n'a pas d'espace membre (fiche, FISS, Vertumetre, RM, devoirs, exercices, demandes...) : ces routes
 *   lui sont refusees (sa fiche resterait sinon visible et ses reponses compteraient dans les suivis) ;
 * - chaque operation d'ecriture et chaque refus sont consignes dans le journal de securite prive
 *   (detection d'un abus ou d'une compromission), jamais dans le journal d'activite ordinaire.
 */
class PlatformManagerGuard
{
    /** Routes de l'espace membre (« METHODE chemin », * = tout). */
    private const MEMBER_SPACE = [
        'PUT api/profile', 'POST api/profile',
        '* api/me/home', '* api/me/overview', '* api/me/month', '* api/me/welcome-back',
        '* api/me/fiss*', '* api/me/vertumetre*', '* api/me/resolutions*', '* api/me/assignments*',
        '* api/me/exercises*', '* api/me/appointments*', '* api/me/requests*', '* api/me/spiritual*', '* api/me/meditations*', '* api/me/resources*',
        '* api/me/evaluations*', '* api/me/tribe-change*', '* api/me/family*', '* api/me/services*',
        'POST api/me/events*', 'PUT api/me/events*', 'DELETE api/me/events*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isPlatformManager()) {
            return $next($request);
        }

        $route = $request->method().' '.($request->route()?->uri() ?? $request->path());
        foreach (self::MEMBER_SPACE as $pattern) {
            [$method, $path] = explode(' ', $pattern, 2);
            if (($method === '*' || $method === $request->method()) && Str::is($path, $request->route()?->uri() ?? '')) {
                SecurityJournal::record('manager.member_space_refused', ['route' => $route], 'info', $user);

                return response()->json(['message' => 'Le compte de gestion n\'a pas d\'espace membre.'], 403);
            }
        }

        $response = $next($request);

        $status = $response->getStatusCode();
        // Entretien automatique de l'appareil (notifications lues a l'ouverture d'une page, abonnement push) : pas une operation.
        $housekeeping = Str::is(['api/me/notifications*', 'api/me/push*'], $request->route()?->uri() ?? '');
        if ((! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! $housekeeping) || $status === 403) {
            SecurityJournal::record($status === 403 ? 'manager.request_refused' : 'manager.request', [
                'route' => $route,
                'status' => $status,
                'params' => $request->route()?->originalParameters() ?? [],
            ], $status === 403 ? 'warning' : 'info', $user);
        }

        return $response;
    }
}
