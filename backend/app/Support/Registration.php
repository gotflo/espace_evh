<?php

namespace App\Support;

use App\Models\User;

/**
 * Inscription : le compte n'ouvre l'application qu'une fois le profil d'inscription complet
 * (prenoms, nom, genre et TRIBU). Tant qu'elle est inachevee, la personne n'apparait chez
 * aucun responsable (listes, statistiques, rapports : filtre is_completed).
 * Pasteurs (PR, PA), Assistants Pasteurs (plusieurs tribus) et administrateur : la tribu leur reste facultative.
 */
class Registration
{
    public const TRIBE_EXEMPT_ROLES = ['super_admin', 'pasteur_assistant', 'assistant_pasteur', 'administrateur'];

    public static function requiresTribe(User $user): bool
    {
        $roles = $user->relationLoaded('roles') ? $user->roles : $user->roles()->get(['roles.id', 'roles.key']);

        return ! $roles->contains(fn ($r) => in_array($r->key, self::TRIBE_EXEMPT_ROLES, true));
    }

    /** Inscription terminee (le compte a acces a l'application). */
    public static function isComplete(User $user): bool
    {
        $profile = $user->profile;

        return $profile !== null && $profile->is_completed
            && ($profile->tribe_id !== null || ! self::requiresTribe($user));
    }

    /** Membre deja inscrit qui doit seulement completer une information devenue obligatoire. */
    public static function isReopened(User $user): bool
    {
        $profile = $user->profile;

        return $profile !== null && ! self::isComplete($user) && filled($profile->matricule);
    }
}
