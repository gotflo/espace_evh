<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Monitoring\Monitor;
use App\Support\Monitoring\Redactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Erreurs d'affichage remontees par le navigateur (erreurs JavaScript, promesses rejetees,
 * ecran en erreur). Ouvert sans connexion (l'ecran de connexion peut aussi planter), limite
 * par adresse IP ; le membre connecte est associe si son jeton est present. Ni URL complete,
 * ni parametres : seulement le chemin de la page et l'extrait masque de la pile.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! config('monitoring.enabled')) {
            return response()->json(['ok' => true]);
        }
        $data = $request->validate([
            'kind' => ['required', 'in:error,unhandledrejection,render,chunk'],
            'message' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:6000'],
            'source' => ['nullable', 'string', 'max:300'],
            'line' => ['nullable', 'integer'],
            'column' => ['nullable', 'integer'],
            'path' => ['nullable', 'string', 'max:300'],
            'component' => ['nullable', 'string', 'max:2000'],
            'release' => ['nullable', 'string', 'max:60'],
        ]);

        // Membre connecte (facultatif) : jeton de l'espace membre uniquement.
        $user = auth('sanctum')->user();
        $path = parse_url((string) ($data['path'] ?? ''), PHP_URL_PATH) ?: null;
        $stack = collect(preg_split('/\R/', (string) ($data['stack'] ?? '')) ?: [])
            ->map(fn ($l) => preg_replace('#https?://[^/\s]+#', '', trim($l)))->filter()->take(12)->values()->all();
        $firstFrame = $stack[1] ?? ($stack[0] ?? (($data['source'] ?? '').':'.($data['line'] ?? '')));
        $frameKey = preg_replace('/-[A-Za-z0-9_-]{6,}\.js/', '.js', (string) $firstFrame); // nom de fichier sans l'empreinte de version

        Monitor::event('error', 'browser', 'client.error', $data['message'], array_filter([
            'kind' => $data['kind'],
            'page' => $path,
            'source' => isset($data['source']) ? preg_replace('#https?://[^/\s]+#', '', $data['source']).':'.($data['line'] ?? '?').':'.($data['column'] ?? '?') : null,
            'stack' => $stack ?: null,
            'component' => isset($data['component']) ? Redactor::text(mb_substr($data['component'], 0, 600), 600) : null,
            'release' => $data['release'] ?? null,
            'browser' => self::device($request->userAgent()),
        ]), 'failure', $user instanceof User ? $user->id : null,
            Redactor::fingerprint('client', $data['kind'], $data['message'], (string) $frameKey));

        return response()->json(['ok' => true]);
    }

    /** Appareil lisible (systeme et navigateur), sans conserver la chaine complete du navigateur. */
    private static function device(?string $ua): string
    {
        if (! $ua) {
            return 'Appareil inconnu';
        }
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'Autre',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Firefox') => 'Firefox', str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Safari') => 'Safari', default => 'navigateur',
        };

        return $os.' - '.$browser;
    }
}
