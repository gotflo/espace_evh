<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Jeton de session. Sanctum enregistre « derniere utilisation » a CHAQUE requete : avec
 * des centaines de membres connectes, cela fait autant d'ecritures concurrentes sur la
 * meme table. On ne l'ecrit qu'une fois toutes les 5 minutes par session.
 * Expiration (SANCTUM_EXPIRATION, calculee depuis la date de creation) : une session encore utilisee
 * apres 30 jours est prolongee (date de creation remise a maintenant). Un membre actif n'est donc
 * jamais deconnecte en bloc ; une session abandonnee expire 60 jours apres sa derniere prolongation.
 */
class PersonalAccessToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    public const TOUCH_EVERY_SECONDS = 300;

    public const RENEW_AFTER_DAYS = 30;

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty('last_used_at') && $this->created_at
            && $this->asDateTime($this->created_at)->lt(now()->subDays(self::RENEW_AFTER_DAYS))) {
            $this->created_at = now();
        }
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
