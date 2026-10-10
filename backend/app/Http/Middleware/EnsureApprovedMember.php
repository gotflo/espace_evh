<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte pas encore valide par le pasteur (ou rejete) : seul l'essentiel de l'ecran d'attente repond
 * (compte, profil d'inscription, adresse courriel, verset du jour, notifications de l'appareil).
 * Tout le reste est refuse, quel que soit le role.
 */
class EnsureApprovedMember
{
    /** Routes ouvertes pendant l'attente (methode et chemin de la route). */
    private const PENDING = [
        'GET api/me', 'POST api/auth/logout', 'GET api/reference', 'GET api/dashboard/verse',
        // Profil d'inscription (avant l'envoi) et adresse courriel (pour etre prevenu de la validation).
        'GET api/profile', 'PUT api/profile', 'POST api/profile', 'PUT api/me/email',
        // Notification « compte valide » sur l'appareil.
        'GET api/me/push', 'POST api/me/push/subscribe', 'POST api/me/push/unsubscribe',
    ];

    /** Compte rejete : il voit la reponse et peut se deconnecter. */
    private const REJECTED = ['GET api/me', 'POST api/auth/logout', 'GET api/dashboard/verse'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->isApproved()) {
            return $next($request);
        }
        $route = $request->method().' '.$request->route()?->uri();
        $allowed = $user->approval_status === User::REJECTED ? self::REJECTED : self::PENDING;
        if (! in_array($route, $allowed, true)) {
            return response()->json([
                'message' => $user->approval_status === User::REJECTED
                    ? 'Votre demande d\'inscription n\'a pas été acceptée.'
                    : 'Votre compte attend la validation du pasteur.',
                'membership' => $user->approval_status,
            ], 403);
        }

        return $next($request);
    }
}
