<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Liens YouTube : seul l'identifiant de la video (11 caracteres) est extrait et conserve.
 * La lecture se fait ensuite depuis youtube-nocookie.com, construit a partir de cet
 * identifiant : aucune URL saisie n'est jamais reinjectee telle quelle dans la page.
 */
class YouTube
{
    private const ID = '/^[A-Za-z0-9_-]{11}$/';

    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'];

    /** Identifiant de la video, ou null si le lien n'est pas une video YouTube valide. */
    public static function videoId(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || mb_strlen($url) > 500) {
            return null;
        }
        if (preg_match(self::ID, $url)) {
            return $url; // identifiant colle directement
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        $candidate = null;
        if ($host === 'youtu.be') {
            $candidate = explode('/', trim($path, '/'))[0] ?? null;
        } elseif (isset($query['v']) && is_string($query['v'])) {
            $candidate = $query['v'];
        } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
            $candidate = $m[1];
        }

        return $candidate !== null && preg_match(self::ID, $candidate) ? $candidate : null;
    }

    public static function thumbnail(string $id): string
    {
        return 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
    }

    public static function watchUrl(string $id): string
    {
        return 'https://www.youtube.com/watch?v='.$id;
    }

    /**
     * Titre et auteur via oEmbed (service public de YouTube). embeddable = false si l'auteur
     * a interdit la lecture hors de YouTube ou si la video est privee / supprimee.
     *
     * @return array{title: ?string, author: ?string, embeddable: bool, reachable: bool}
     */
    public static function details(string $id): array
    {
        if (! preg_match(self::ID, $id)) {
            return ['title' => null, 'author' => null, 'embeddable' => false, 'reachable' => false];
        }
        try {
            $response = Http::timeout(4)->acceptJson()
                ->get('https://www.youtube.com/oembed', ['url' => self::watchUrl($id), 'format' => 'json']);
        } catch (\Throwable) {
            // YouTube injoignable depuis le serveur : on n'empeche pas la publication.
            return ['title' => null, 'author' => null, 'embeddable' => true, 'reachable' => false];
        }
        if (! $response->successful()) {
            return ['title' => null, 'author' => null, 'embeddable' => false, 'reachable' => true];
        }

        return [
            'title' => is_string($response->json('title')) ? mb_substr($response->json('title'), 0, 200) : null,
            'author' => is_string($response->json('author_name')) ? mb_substr($response->json('author_name'), 0, 120) : null,
            'embeddable' => true,
            'reachable' => true,
        ];
    }
}
