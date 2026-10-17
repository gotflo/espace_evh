<?php

namespace App\Support;

/**
 * Mode d'essai LOCAL (TEST_DIRECT_LOGIN=true ET APP_ENV=local) : connexion sans code SMS et inscriptions
 * validees aussitot, pour tester l'application sans attendre. Jamais actif en production : l'environnement
 * local est exige en plus de l'option.
 */
class LocalTestMode
{
    public static function active(): bool
    {
        return (bool) config('app.test_direct_login') && app()->environment('local') && self::isLocalHost();
    }

    /**
     * La requete vise-t-elle une machine locale (localhost, 127.0.0.1, *.localhost, *.test) ? Un .env de
     * production recopie par erreur avec APP_ENV=local n'ouvre donc rien sur le vrai nom de domaine.
     */
    public static function isLocalHost(): bool
    {
        $host = strtolower((string) (request()->getHost() ?: parse_url((string) config('app.url'), PHP_URL_HOST)));

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }
}
