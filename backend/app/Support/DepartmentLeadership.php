<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Responsables de departement : une seule liste, deux portes d'entree toujours synchronisees.
 * - Attribuer le role « Responsable de departement » sur un departement (fiche du membre, Comptes)
 *   le nomme responsable dans Organisation (et l'ajoute au departement s'il n'en est pas membre).
 * - Nommer un responsable dans Organisation lui attribue ce role sur le departement.
 * Retirer l'un retire l'autre (meme logique que le Garde et son GEM).
 */
class DepartmentLeadership
{
    /** Cle du role de responsable de departement (portee : un departement). */
    public const ROLE = 'responsable_departement';

    /** Responsables au plus par departement. */
    public const MAX = 5;

    public static function isLeaderRole(?Role $role, ?string $scopeKind): bool
    {
        return $role?->key === self::ROLE && $scopeKind === 'department';
    }

    /**
     * Le membre peut-il etre nomme responsable de ce departement ? Profil complet, et 5 responsables au plus.
     */
    public static function assertCanLead(Department $department, int $userId): void
    {
        abort_unless(Profile::where('user_id', $userId)->where('is_completed', true)->exists(), 422,
            'Un responsable doit avoir un profil complet.');
        $leaders = $department->leaders()->pluck('users.id')->map(fn ($id) => (int) $id);
        abort_if(! $leaders->contains($userId) && $leaders->count() >= self::MAX, 422,
            "Le département {$department->name} a déjà ".self::MAX.' responsables : retirez-en un dans Organisation.');
    }

    /** Role attribue : le membre devient responsable dans Organisation (et membre du departement). */
    public static function appoint(Department $department, int $userId): void
    {
        $profileId = Profile::where('user_id', $userId)->value('id');
        if ($profileId && ! $department->members()->whereKey($profileId)->exists()) {
            $department->members()->attach($profileId);
            Audit::log('department.members_added', $department, null, [], ['profile_ids' => [$profileId]], ['reason' => 'nomme responsable']);
        }
        $before = self::leaderIds($department);
        if (! in_array($userId, $before, true)) {
            $department->leaders()->attach($userId);
            Audit::log('department.leaders_updated', $department, null, ['leaders' => $before], ['leaders' => self::leaderIds($department)], ['via' => 'role']);
        }
    }

    /** Role retire : le membre n'est plus responsable dans Organisation (il reste membre du departement). */
    public static function dismiss(Department $department, int $userId): void
    {
        $before = self::leaderIds($department);
        if (in_array($userId, $before, true)) {
            $department->leaders()->detach($userId);
            Audit::log('department.leaders_updated', $department, null, ['leaders' => $before], ['leaders' => self::leaderIds($department)], ['via' => 'role']);
        }
    }

    /**
     * Organisation : la liste des responsables vient de changer, le role suit (attribue aux nouveaux,
     * retire aux anciens). Sans notification : celle d'Organisation suffit.
     *
     * @param  array<int>  $before
     * @param  array<int>  $after
     */
    public static function syncRole(Department $department, array $before, array $after, int $actorId): void
    {
        $role = Role::where('key', self::ROLE)->first();
        if (! $role) {
            return;
        }
        $scope = ['role_id' => $role->id, 'scope_kind' => 'department', 'scope_id' => $department->id];

        foreach (array_diff($after, $before) as $userId) {
            if (! DB::table('role_user')->where($scope + ['user_id' => $userId])->exists()) {
                DB::table('role_user')->insert($scope + ['user_id' => $userId, 'assigned_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
                Audit::log('role.assigned', User::find($userId), $userId, [], ['role' => $role->key, 'scope_kind' => 'department', 'scope_id' => $department->id], ['via' => 'organisation']);
            }
        }
        foreach (array_diff($before, $after) as $userId) {
            if (DB::table('role_user')->where($scope + ['user_id' => $userId])->delete()) {
                Audit::log('role.revoked', User::find($userId), $userId, ['role' => $role->key, 'scope_kind' => 'department', 'scope_id' => $department->id], [], ['via' => 'organisation']);
            }
        }
    }

    /**
     * Le membre quitte le departement (de lui-meme, ou par la fiche du membre) : s'il en etait responsable,
     * il ne l'est plus, et le role suit.
     */
    public static function leave(Department $department, int $userId, ?int $actorId): void
    {
        $before = self::leaderIds($department);
        if (! in_array($userId, $before, true)) {
            return;
        }
        self::dismiss($department, $userId);
        self::syncRole($department, $before, self::leaderIds($department), $actorId ?? $userId);
    }

    /** Departement supprime : ses attributions du role disparaissent avec lui. */
    public static function forget(Department $department): void
    {
        $roleId = Role::where('key', self::ROLE)->value('id');
        if ($roleId) {
            DB::table('role_user')->where('role_id', $roleId)->where('scope_kind', 'department')->where('scope_id', $department->id)->delete();
        }
    }

    /** @return array<int> */
    private static function leaderIds(Department $department): array
    {
        return $department->leaders()->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }
}
