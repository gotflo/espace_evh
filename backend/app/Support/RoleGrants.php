<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Anti-escalade des roles, la meme partout (Roles, Comptes, fiche du membre, Organisation, GEMs) :
 * - le Pasteur Resident fait tout ; personne d'autre ne touche a son role ;
 * - on n'attribue qu'un role de rang inferieur au sien, et jamais a soi-meme ;
 * - on ne confie pas un acces confidentiel (FISS, suivi spirituel, fiches...) qu'on n'a pas soi-meme ;
 * - l'autorite pastorale (voir tous les membres) ne s'ajoute a un role que par le Pasteur Resident.
 * Le compte de gestion (sans aucun acces confidentiel) attribue les roles existants, sauf celui du
 * Pasteur Resident, mais n'ajoute ni ne retire de permission confidentielle dans un role.
 */
class RoleGrants
{
    /** Permission qui fait d'un role une autorite pastorale (portee : toute l'eglise). */
    private const PASTORAL_AUTHORITY = 'members.view_all';

    /** @return array<int, string> permissions confidentielles du role */
    public static function confidentialKeys(Role $role): array
    {
        return $role->permissions()->pluck('key')->filter(fn ($k) => PermissionCatalog::isConfidential($k))->values()->all();
    }

    /**
     * Pourquoi cet utilisateur ne peut pas attribuer (ou retirer) ce role, ou null s'il le peut.
     * $target : le membre concerne (null pour un simple affichage des droits).
     */
    public static function assignRefusal(User $actor, Role $role, ?User $target = null): ?string
    {
        if ($actor->isSuperAdmin()) {
            return null;
        }
        if ($role->key === User::SUPER_ADMIN) {
            return 'Seul le Pasteur Résident peut attribuer ou retirer ce rôle.';
        }
        if ($target && $target->id === $actor->id) {
            return 'Vous ne pouvez pas modifier vos propres rôles : demandez au Pasteur Résident.';
        }
        if ($actor->isPlatformManager()) {
            return null;
        }
        if ($role->rank >= $actor->highestRank()) {
            return 'Vous ne pouvez pas attribuer un rôle de niveau supérieur ou égal au vôtre.';
        }
        $missing = array_values(array_filter(self::confidentialKeys($role), fn ($k) => ! $actor->hasPermission($k)));
        if ($missing) {
            return 'Ce rôle donne accès à des données confidentielles que vous n\'avez pas vous-même ('
                .self::labels($missing).') : seul le Pasteur Résident peut l\'attribuer.';
        }

        return null;
    }

    /**
     * Pourquoi cet utilisateur ne peut pas changer ainsi les permissions d'un role, ou null.
     *
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     * @param  array<int, string>  $wanted  permissions du role apres modification
     */
    public static function editRefusal(User $actor, array $added, array $removed, array $wanted): ?string
    {
        if ($actor->isSuperAdmin()) {
            return null;
        }
        if (in_array(self::PASTORAL_AUTHORITY, $added, true)) {
            return 'Seul le Pasteur Résident peut donner à un rôle l\'accès à tous les membres de l\'église.';
        }
        $denied = array_values(array_filter(array_merge($added, $removed), fn ($k) => $actor->isPlatformManager()
            ? PermissionCatalog::isConfidential($k)
            : ! $actor->hasPermission($k)));
        if ($denied) {
            return 'Vous ne pouvez pas donner ou retirer une permission que vous n\'avez pas : '.self::labels($denied).'.';
        }
        // Ajouter un acces confidentiel a un role qui en a deja d'autres : il faut les detenir tous
        // (sinon la combinaison donnerait plus que ce que l'on a soi-meme, ex. toutes les FISS).
        if (array_filter($added, fn ($k) => PermissionCatalog::isConfidential($k))) {
            $missing = array_values(array_filter($wanted, fn ($k) => PermissionCatalog::isConfidential($k) && ! $actor->hasPermission($k)));
            if ($missing) {
                return 'Ce rôle combinerait des accès confidentiels que vous n\'avez pas : '.self::labels($missing).'.';
            }
        }

        return null;
    }

    /** @param array<int, string> $keys */
    private static function labels(array $keys): string
    {
        $all = PermissionCatalog::all();

        return implode(', ', array_map(fn ($k) => mb_strtolower($all[$k][0] ?? $k), $keys));
    }
}
