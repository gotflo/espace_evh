<?php

namespace App\Support;

use App\Models\Gem;
use App\Models\Role;
use App\Models\User;

/**
 * Anti-escalade des roles, la meme partout (Roles, Comptes, fiche du membre, Organisation, GEMs) :
 * - le Pasteur Resident fait tout ; personne d'autre ne touche a son role ;
 * - on n'attribue qu'un role de rang inferieur au sien, et jamais a soi-meme ;
 * - on ne confie pas un acces confidentiel (FISS, suivi spirituel, fiches...) qu'on n'a pas soi-meme, et
 *   seulement la ou on l'a : un droit recu sur un GEM ou une tribu ne permet pas de le donner a toute l'eglise
 *   (role sans portee) ni sur une autre tribu ;
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
     * Detient-il ce droit confidentiel la ou il veut le donner ? Sans portee ($kind null) : pour toute l'eglise.
     * Avec portee : pour toute l'eglise, ou sur cette tribu / ce GEM (ou sa tribu) / ce departement / ce fidele.
     */
    private static function holds(User $actor, string $key, ?string $kind = null, ?int $scopeId = null): bool
    {
        if (! $actor->hasPermission($key)) {
            return false;
        }
        if ($actor->isSuperAdmin() || $actor->isChurchWideFor($key)) {
            return true;
        }
        if (! $kind || ! $scopeId) {
            return false;
        }
        $scopes = $actor->scopesFor($key);

        return match ($kind) {
            'tribe' => in_array($scopeId, $scopes['tribe'], true),
            'gem' => in_array($scopeId, $scopes['gem'], true)
                || in_array((int) Gem::whereKey($scopeId)->value('tribe_id'), $scopes['tribe'], true),
            'department' => in_array($scopeId, $scopes['department'], true),
            'member' => ($member = User::find($scopeId)) !== null && $actor->canManageMember($member, $key),
            default => false,
        };
    }

    /**
     * Pourquoi cet utilisateur ne peut pas attribuer (ou retirer) ce role, ou null s'il le peut.
     * $target : le membre concerne (null pour un simple affichage des droits) ; avec $target, chaque acces
     * confidentiel du role doit etre detenu sur la portee de l'attribution ($scopeKind / $scopeId ; aucune
     * portee = toute l'eglise).
     */
    public static function assignRefusal(User $actor, Role $role, ?User $target = null, ?string $scopeKind = null, ?int $scopeId = null): ?string
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
        // Attribution reelle : ces acces confidentiels, il faut les avoir LA ou on les donne.
        if ($target) {
            $kind = $role->scope_kind && $role->scope_kind !== 'none' ? ($scopeKind ?? $role->scope_kind) : null;
            $outside = array_values(array_filter(self::confidentialKeys($role), fn ($k) => ! self::holds($actor, $k, $kind, $scopeId)));
            if ($outside) {
                return $kind
                    ? 'Vous n\'avez pas ces accès confidentiels sur cette portée ('.self::labels($outside).') : choisissez une portée qui est la vôtre, ou demandez au Pasteur Résident.'
                    : 'Ce rôle donne ces accès à toute l\'église ('.self::labels($outside).') : vous ne les avez que sur votre portée. Seul le Pasteur Résident peut l\'attribuer.';
            }
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
    public static function editRefusal(User $actor, array $added, array $removed, array $wanted, ?string $roleKey = null): ?string
    {
        // Le role « fidele » est donne a tout le monde : jamais d'acces confidentiel dedans, meme par le PR.
        if ($roleKey === 'fidele' && ($bad = array_values(array_filter($added, fn ($k) => PermissionCatalog::isConfidential($k))))) {
            return 'Le rôle des fidèles est donné à tout le monde : il ne peut pas recevoir d\'accès confidentiel ('.self::labels($bad).').';
        }
        if ($actor->isSuperAdmin()) {
            return null;
        }
        if (in_array(self::PASTORAL_AUTHORITY, $added, true)) {
            return 'Seul le Pasteur Résident peut donner à un rôle l\'accès à tous les membres de l\'église.';
        }
        // Un acces confidentiel ne se donne (ou ne se retire) dans un role que si on l'a pour toute l'eglise : un
        // droit recu sur un GEM ou une tribu ne doit pas devenir, par un role, un droit sur tous les membres.
        $denied = array_values(array_filter(array_merge($added, $removed), fn ($k) => $actor->isPlatformManager()
            ? PermissionCatalog::isConfidential($k)
            : (PermissionCatalog::isConfidential($k) ? ! self::holds($actor, $k) : ! $actor->hasPermission($k))));
        if ($denied) {
            return 'Vous ne pouvez pas donner ou retirer une permission que vous n\'avez pas : '.self::labels($denied).'.';
        }
        // Ajouter un acces confidentiel a un role qui en a deja d'autres : il faut les detenir tous
        // (sinon la combinaison donnerait plus que ce que l'on a soi-meme, ex. toutes les FISS).
        if (array_filter($added, fn ($k) => PermissionCatalog::isConfidential($k))) {
            $missing = array_values(array_filter($wanted, fn ($k) => PermissionCatalog::isConfidential($k) && ! self::holds($actor, $k)));
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
