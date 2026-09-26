<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Jeton de session. Sanctum enregistre « derniere utilisation » a CHAQUE requete : avec
 * des centaines de membres connectes, cela fait autant d'ecritures concurrentes sur la
 * meme table. On ne l'ecrit qu'une fois toutes les 5 minutes par session : l'expiration
 * (SANCTUM_EXPIRATION) reste calculee sur la date de creation et n'est pas affectee.
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    public const TOUCH_EVERY_SECONDS = 300;

    public function save(array $options = []): bool
    {
        $dirty = array_keys($this->getDirty());
        $previous = $this->getOriginal('last_used_at');
        if ($this->exists && $dirty === ['last_used_at'] && $previous
            && $this->asDateTime($previous)->gt(now()->subSeconds(self::TOUCH_EVERY_SECONDS))) {
            $this->syncOriginalAttribute('last_used_at');

            return true;
        }

        return parent::save($options);
    }
}
