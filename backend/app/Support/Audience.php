<?php

namespace App\Support;

use App\Models\User;

/**
 * Resout la liste des utilisateurs cibles par une portee (toute l'eglise,
 * une tribu, un departement, un GEM). Utilise pour diffuser annonces / exercices.
 */
class Audience
{
    /** @return array<int> ids des utilisateurs cibles */
    public static function userIds(string $targetType, ?int $targetId): array
    {
        $query = User::query()->whereHas('profile');

        if ($targetType === 'tribe') {
            $query->whereHas('profile', fn ($p) => $p->where('tribe_id', $targetId));
        } elseif ($targetType === 'department') {
            $query->whereHas('profile.departments', fn ($d) => $d->where('departments.id', $targetId));
        } elseif ($targetType === 'gem') {
            $query->whereHas('profile', fn ($p) => $p->where('gem_id', $targetId));
        }

        return $query->pluck('id')->all();
    }

    /**
     * Audience limitee a la portee de l'auteur : un responsable restreint (GAD, patriarche,
     * responsable de dept) ne peut diffuser qu'a ses propres membres, quelle que soit la cible.
     *
     * @return array<int>
     */
    public static function forActor(User $actor, string $targetType, ?int $targetId): array
    {
        $requested = self::userIds($targetType, $targetId);

        if ($actor->hasPermission('members.view_all')) {
            return $requested;
        }

        $visible = MemberScope::visibleUserIds($actor);

        return array_values(array_intersect($requested, $visible));
    }
}
