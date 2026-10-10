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
        return (bool) config('app.test_direct_login') && app()->environment('local');
    }
}
