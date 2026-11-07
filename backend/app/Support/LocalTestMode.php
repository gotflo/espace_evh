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
     * L'adresse configuree de l'application (APP_URL) doit AUSSI etre locale : un en-tete Host forge
     * (« Host: x.test ») envoye au vrai serveur n'ouvre rien.
     */
    public static function isLocalHost(): bool
    {
        $local = fn (string $host) => in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
        $configured = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $requested = strtolower((string) (request()->getHost() ?: $configured));

        return $local($configured) && $local($requested);
    }
}
