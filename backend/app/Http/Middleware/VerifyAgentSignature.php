<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * API de controle de l'agent de supervision : seule la console (projet evh_monitoring), qui
 * connait le secret partage MONITOR_AGENT_SECRET, peut l'appeler.
 *
 * Signature attendue (en-tete X-Agent-Signature, hexadecimal) :
 *   HMAC-SHA256(secret, horodatage \n METHODE \n chemin \n parametres tries \n auteur \n sha256(corps))
 * avec X-Agent-Timestamp (secondes Unix) et X-Agent-Actor (personne de la console, encodee URL).
 * Une requete trop ancienne (ou trop en avance) ou deja vue est refusee (anti-rejeu).
 */
class VerifyAgentSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('monitoring.agent_secret');
        if (strlen($secret) < 32) {
            abort(503, 'API de contrôle de la supervision non configurée (MONITOR_AGENT_SECRET).');
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) config('monitoring.agent_allowed_ips'))));
        if ($allowed && ! in_array($request->ip(), $allowed, true)) {
            abort(403, 'Adresse non autorisée.');
        }

        $timestamp = (string) $request->header('X-Agent-Timestamp', '');
        $signature = strtolower((string) $request->header('X-Agent-Signature', ''));
        $skew = max(30, (int) config('monitoring.agent_max_skew', 300));
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $skew || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            abort(401, 'Signature absente ou expirée.');
        }

        $expected = hash_hmac('sha256', self::canonical($timestamp, $request->getMethod(), '/'.ltrim($request->path(), '/'),
            $request->query->all(), (string) $request->header('X-Agent-Actor', ''), $request->getContent()), $secret);
        if (! hash_equals($expected, $signature)) {
            abort(401, 'Signature invalide.');
        }
        // Une signature ne sert qu'une fois.
        if (! Cache::add('agent-signature:'.$signature, 1, $skew * 2)) {
            abort(401, 'Requête déjà reçue.');
        }

        return $next($request);
    }

    /** Chaine signee : identique a celle construite par la console (AgentClient). */
    public static function canonical(string $timestamp, string $method, string $path, array $query, string $actor, string $body): string
    {
        ksort($query);

        return implode("\n", [$timestamp, strtoupper($method), $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $actor, hash('sha256', $body)]);
    }
}
