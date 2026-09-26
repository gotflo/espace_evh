<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Portee d'un utilisateur sur les membres : centralise la logique GEM / tribu / departement /
 * fideles confies. members.view_all (autorite pastorale) => tout le monde ; sinon uniquement
 * sa portee (un AP ne voit que les tribus qui lui sont assignees). Applique a toutes les
 * listes, exports, rapports et diffusions : aucun appel d'API ne peut la contourner.
 */
class MemberScope
{
    /** Membres que l'utilisateur peut consulter. */
    public static function scopeProfiles(Builder $query, User $user): Builder
    {
        if ($user->hasPermission('members.view_all')) {
            return $query;
        }

        $tribeIds = $user->scopeTribeIds();
        $deptIds = $user->scopeDepartmentIds();
        $gemIds = $user->scopeGemIds();
        $memberIds = $user->scopeMemberIds();
        $table = $query->getModel()->getTable();

        return $query->where(function ($sub) use ($tribeIds, $deptIds, $gemIds, $memberIds, $table) {
            $applied = false;
            if ($gemIds) { $sub->orWhereIn("{$table}.gem_id", $gemIds); $applied = true; }
            if ($tribeIds) { $sub->orWhereIn("{$table}.tribe_id", $tribeIds); $applied = true; }
            if ($memberIds) { $sub->orWhereIn("{$table}.user_id", $memberIds); $applied = true; }
            if ($deptIds) {
                $sub->orWhereHas('departments', fn ($d) => $d->whereIn('departments.id', $deptIds));
                $applied = true;
            }
            if (! $applied) {
                $sub->whereRaw('1 = 0'); // aucune portee => aucun membre
            }
        });
    }

    /** Membres sur lesquels l'utilisateur peut agir (meme portee que la consultation). */
    public static function manageableProfiles(Builder $query, User $user): Builder
    {
        return self::scopeProfiles($query, $user);
    }

    /** Ids des utilisateurs visibles par cet utilisateur. */
    public static function visibleUserIds(User $user): array
    {
        return self::scopeProfiles(Profile::query(), $user)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function manageableUserIds(User $user): array
    {
        return self::visibleUserIds($user);
    }

    /** L'utilisateur a-t-il une portee restreinte (n'est pas une autorite pastorale) ? */
    public static function isScoped(User $user): bool
    {
        return ! $user->hasPermission('members.view_all');
    }

    /**
     * Tribus que l'utilisateur peut suivre dans les rapports : toutes (autorite) ou les siennes.
     *
     * @return array<int>|null null = toutes
     */
    public static function reportTribeIds(User $user): ?array
    {
        return $user->hasPermission('members.view_all') ? null : $user->scopeTribeIds();
    }
}
