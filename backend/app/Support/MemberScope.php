<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Portee d'un utilisateur sur les membres : centralise la logique tribu / departement / GEM.
 * view_all => tout ; sinon uniquement sa/ses tribu(s), departement(s) et GEM(s).
 */
class MemberScope
{
    /** Applique la portee de l'utilisateur a une requete sur les Profils. */
    public static function scopeProfiles(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('members.view_all')) {
            return $query;
        }

        $tribeIds = $user->scopeTribeIds();
        $deptIds = $user->scopeDepartmentIds();
        $gemIds = $user->scopeGemIds();

        return $query->where(function ($sub) use ($tribeIds, $deptIds, $gemIds) {
            $applied = false;
            if ($gemIds) { $sub->orWhereIn('gem_id', $gemIds); $applied = true; }
            if ($tribeIds) { $sub->orWhereIn('tribe_id', $tribeIds); $applied = true; }
            if ($deptIds) {
                $sub->orWhereHas('departments', fn ($d) => $d->whereIn('departments.id', $deptIds));
                $applied = true;
            }
            if (! $applied) {
                $sub->whereRaw('1 = 0'); // aucune portee => aucun membre
            }
        });
    }

    /** Ids des utilisateurs visibles par cet utilisateur (selon sa portee). */
    public static function visibleUserIds(User $user): array
    {
        return self::scopeProfiles(Profile::query(), $user)->pluck('user_id')->all();
    }

    /** L'utilisateur a-t-il une portee restreinte (n'est pas view_all) ? */
    public static function isScoped(User $user): bool
    {
        return ! $user->hasPermission('members.view_all');
    }

    /**
     * Portee principale de l'utilisateur pour cibler ses diffusions (exercices, evenements).
     * Priorite GEM > tribu > departement. view_all => ['all', null].
     *
     * @return array{0: string, 1: int|null}
     */
    public static function primaryScope(User $user): array
    {
        if ($user->hasPermission('members.view_all')) {
            return ['all', null];
        }
        if ($g = $user->scopeGemIds()) {
            return ['gem', $g[0]];
        }
        if ($t = $user->scopeTribeIds()) {
            return ['tribe', $t[0]];
        }
        if ($d = $user->scopeDepartmentIds()) {
            return ['department', $d[0]];
        }

        return ['all', null];
    }
}
