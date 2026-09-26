<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Qui doit etre prevenu / qui peut valider ? Resout les destinataires des notifications
 * automatiques et les valideurs des demandes, toujours dans le respect des portees.
 */
class Recipients
{
    /** Responsables charges une fois pour la duree d'un traitement groupe (voir remember()). */
    private static ?Collection $batchStaff = null;

    /**
     * Execute un traitement groupe (passage des automatismes) en chargeant la liste des
     * responsables une seule fois ; elle est liberee a la fin, meme en cas d'erreur.
     */
    public static function remember(callable $callback): mixed
    {
        $previous = self::$batchStaff;
        self::$batchStaff = null;
        self::$batchStaff = self::staff();
        try {
            return $callback();
        } finally {
            self::$batchStaff = $previous;
        }
    }

    /**
     * Responsables potentiels : membres dont un role donne une permission de gestion
     * (tout sauf « repondre aux exercices », que tout fidele possede) ou qui dirigent un
     * departement : quelques dizaines de personnes, et non toute l'eglise (sans ce filtre,
     * chaque notification chargeait tous les membres : cout N x N).
     */
    private static function staff(): Collection
    {
        return self::$batchStaff ?? User::where(fn ($q) => $q
            ->whereHas('roles.permissions', fn ($p) => $p->where('key', '!=', 'exercises.respond'))
            ->orWhereHas('roles', fn ($r) => $r->where('key', 'super_admin'))
            ->orWhereHas('ledDepartments'))
            ->with('roles.permissions', 'ledDepartments:id')->get();
    }

    /** Profil et departements du membre charges une fois (et non pour chaque responsable teste). */
    private static function prepare(User $member): User
    {
        return $member->loadMissing('profile.departments:id');
    }

    /** @return array<int> ids des utilisateurs ayant cette permission (super admin inclus). */
    public static function withPermission(string $permission): array
    {
        return self::staff()->filter(fn (User $u) => $u->hasPermission($permission))
            ->pluck('id')->values()->all();
    }

    /**
     * Responsables directs d'un fidele (Garde de son GEM, patriarche / AP de sa tribu,
     * responsables de ses departements) : hors autorites pastorales.
     *
     * @return array<int>
     */
    public static function leadersOf(User $member): array
    {
        self::prepare($member);

        return self::staff()
            ->filter(fn (User $u) => $u->id !== $member->id
                && ! $u->hasPermission('members.view_all')
                && $u->canManageMember($member))
            ->pluck('id')->values()->all();
    }

    /**
     * Personnes qui suivent un fidele et ont la permission demandee (ex. requests.handle).
     *
     * @return array<int>
     */
    public static function followersOf(User $member, string $permission): array
    {
        self::prepare($member);

        return self::staff()
            ->filter(fn (User $u) => $u->id !== $member->id
                && $u->hasPermission($permission)
                && $u->canManageMember($member))
            ->pluck('id')->values()->all();
    }

    /** @return array<int> tous ceux qui ont la charge de ce fidele (pasteurs + responsables de sa portee). */
    public static function watchersOf(User $member): array
    {
        self::prepare($member);

        return self::staff()
            ->filter(fn (User $u) => $u->id !== $member->id && $u->canManageMember($member))
            ->pluck('id')->values()->all();
    }

    /**
     * Titulaires d'un role sur une tribu (ex. patriarche, assistant_pasteur).
     *
     * @param  array<int, string>  $roleKeys
     * @return array<int>
     */
    public static function tribeRoleHolders(int $tribeId, array $roleKeys): array
    {
        $roleIds = Role::whereIn('key', $roleKeys)->pluck('id');

        return DB::table('role_user')->whereIn('role_id', $roleIds)
            ->where('scope_kind', 'tribe')->where('scope_id', $tribeId)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Responsables d'une tribu pour les validations et alertes, du plus proche au plus eleve :
     * patriarche(s), a defaut AP de la tribu, a defaut les autorites pastorales ayant la permission.
     *
     * @return array<int>
     */
    public static function tribeLeadersFor(?int $tribeId, string $permission, ?int $exceptUserId = null): array
    {
        $filter = fn (array $ids) => array_values(array_filter($ids, fn ($id) => $id !== $exceptUserId
            && User::find($id)?->hasPermission($permission)));

        if ($tribeId) {
            foreach ([['patriarche'], ['assistant_pasteur']] as $keys) {
                if ($ids = $filter(self::tribeRoleHolders($tribeId, $keys))) {
                    return $ids;
                }
            }
        }

        return array_values(array_filter(
            self::staff()->filter(fn (User $u) => $u->hasPermission('members.view_all') && $u->hasPermission($permission))
                ->pluck('id')->all(),
            fn ($id) => $id !== $exceptUserId,
        ));
    }

    /** @return array<int> responsables d'un departement (responsables designes + roles sur ce departement). */
    public static function departmentLeaders(Department $department): array
    {
        $ids = $department->leaders()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $roleScoped = DB::table('role_user')->where('scope_kind', 'department')->where('scope_id', $department->id)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_merge($ids, $roleScoped)));
    }
}
