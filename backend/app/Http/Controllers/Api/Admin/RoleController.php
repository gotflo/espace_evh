<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Audit;
use App\Support\DepartmentLeadership;
use App\Support\GemRules;
use App\Support\PermissionCatalog;
use App\Support\PlatformManager;
use App\Support\SecurityJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Roles et permissions. Anti-escalade : on ne donne pas une permission qu'on n'a pas, on ne modifie,
 * n'attribue ni ne retire un role de rang superieur ou egal au sien. Le Pasteur Resident fait tout ;
 * le compte de gestion principal gere tous les roles sauf celui de Pasteur Resident, et n'a lui-meme
 * aucun role (ses droits n'en dependent pas).
 */
class RoleController extends Controller
{
    /** Liste des roles (pour le menu d'attribution ET la gestion des roles). */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('roles.assign') || $actor->hasPermission('roles.manage'), 403, 'Vous n\'avez pas la permission requise.');

        $roles = Role::with('permissions:id,key')->withCount('users')->orderByDesc('rank')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'key' => $r->key,
                'name' => $r->name,
                'description' => $r->description,
                'scope_kind' => $r->scope_kind, // none | tribe | gem | department | member
                'is_system' => $r->is_system,
                // Le Pasteur Resident a toujours toutes les permissions.
                'permission_keys' => $r->key === User::SUPER_ADMIN ? PermissionCatalog::keys() : $r->permissions->pluck('key')->values(),
                'users_count' => $r->users_count,
                'rank' => $r->rank,
                // Ce que l'utilisateur connecte peut faire de ce role (memes regles que le serveur).
                'can_edit' => $this->canEditRole($actor, $r),
                'can_assign' => $this->canGrantRole($actor, $r),
            ]);

        return response()->json(['roles' => $roles]);
    }

    /**
     * Catalogue des permissions par module (onglet) pour construire un role : nature de chaque
     * permission (onglet, consulter, creer, supprimer...), donnees personnelles ou non, et si
     * l'utilisateur connecte peut la donner (on ne donne pas une permission qu'on n'a pas).
     */
    public function permissions(Request $request): JsonResponse
    {
        $actor = $request->user();
        $known = Permission::pluck('key')->flip();
        $groups = collect(PermissionCatalog::MODULES)->map(fn ($module, $key) => [
            'key' => $key,
            'group' => $module[0],
            'permissions' => collect($module[1])->filter(fn ($def, $perm) => isset($known[$perm]))->map(fn ($def, $perm) => [
                'key' => $perm,
                'name' => $def[0],
                'kind' => $def[1],
                'kind_label' => PermissionCatalog::KINDS[$def[1]] ?? $def[1],
                'confidential' => $def[2],
                'grantable' => $this->canGrantPermission($actor, $perm),
            ])->values(),
        ])->filter(fn ($g) => $g['permissions']->isNotEmpty())->values();

        return response()->json(['groups' => $groups]);
    }

    /** Creer un role personnalise. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRole($request);
        $keys = array_values($data['permission_keys'] ?? []);
        $this->ensureGrantable($request->user(), $keys);

        $role = Role::create([
            'key' => $this->uniqueKey($data['name']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope_kind' => $data['scope_kind'],
            'rank' => 30, // entre les fonctions et fidele, ajustable
            'is_system' => false,
        ]);
        $role->permissions()->sync($this->permissionIds($keys));
        Audit::log('role.created', $role, null, [], ['name' => $role->name, 'scope_kind' => $role->scope_kind, 'permissions' => $keys]);

        return response()->json(['message' => 'Rôle créé.', 'role_id' => $role->id]);
    }

    /** Modifier un role (personnalise ou de base : permissions/nom/description). */
    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $this->validateRole($request);
        $actor = $request->user();
        abort_unless($this->canEditRole($actor, $role), 403, $role->key === User::SUPER_ADMIN
            ? 'Le rôle de Pasteur Résident a toujours toutes les permissions : il n’est pas modifiable.'
            : 'Vous ne pouvez pas modifier un rôle de niveau supérieur ou égal au vôtre.');

        $before = $role->permissions()->pluck('key')->all();
        $wanted = array_values($data['permission_keys'] ?? []);
        $added = array_values(array_diff($wanted, $before));
        $removed = array_values(array_diff($before, $wanted));
        // Seules les permissions ajoutees ou retirees doivent etre a la portee de l'auteur.
        $this->ensureGrantable($actor, array_merge($added, $removed));
        $beforeRole = $role->only(['name', 'description', 'scope_kind']);

        $payload = ['name' => $data['name'], 'description' => $data['description'] ?? null];
        // On ne change pas la portee des roles de base (structure figee).
        if (! $role->is_system) {
            $payload['scope_kind'] = $data['scope_kind'];
        }
        DB::transaction(function () use ($role, $payload, $wanted) {
            $role->update($payload);
            $role->permissions()->sync($this->permissionIds($wanted));
        });

        [$old, $new] = Audit::diff($beforeRole, $role->only(array_keys($beforeRole)));
        if ($old || $new || $added || $removed) {
            Audit::log('role.updated', $role, null,
                $old + ($removed ? ['permissions_removed' => $removed] : []),
                $new + ($added ? ['permissions_added' => $added] : []),
                ['role' => $role->key]);
        }

        return response()->json(['message' => 'Rôle mis à jour.']);
    }

    /** Supprimer un role personnalise (les roles de base sont proteges). */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            abort(403, 'Les rôles de base ne peuvent pas être supprimés.');
        }
        abort_unless($this->canEditRole($request->user(), $role), 403, 'Vous ne pouvez pas supprimer un rôle de niveau supérieur ou égal au vôtre.');
        Audit::log('role.deleted', $role, null, [
            'name' => $role->name,
            'permissions' => $role->permissions()->pluck('key')->all(),
            'holders' => $role->users()->count(),
        ]);
        $role->delete(); // les attributions (role_user) sont supprimees en cascade

        return response()->json(['message' => 'Rôle supprimé.']);
    }

    private function validateRole(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
            'scope_kind' => ['required', 'in:none,tribe,gem,department,member'],
            'permission_keys' => ['nullable', 'array'],
            'permission_keys.*' => ['string', 'distinct', 'exists:permissions,key'],
        ]);
    }

    /**
     * Anti-escalade : le Pasteur Resident et le compte de gestion donnent toute permission ; les autres
     * seulement celles qu'ils ont eux-memes (sinon un role modifie leur donnerait davantage).
     */
    private function canGrantPermission(User $actor, string $permission): bool
    {
        return $actor->isSuperAdmin() || $actor->isPlatformManager() || $actor->hasPermission($permission);
    }

    /** @param array<int, string> $keys */
    private function ensureGrantable(User $actor, array $keys): void
    {
        $denied = array_values(array_filter($keys, fn ($k) => ! $this->canGrantPermission($actor, $k)));
        if ($denied) {
            throw ValidationException::withMessages([
                'permission_keys' => 'Vous ne pouvez pas donner ou retirer une permission que vous n\'avez pas : '.implode(', ', $denied).'.',
            ]);
        }
    }

    /** Modifier (ou supprimer) un role : jamais celui du Pasteur Resident ; sinon rang inferieur au sien. */
    private function canEditRole(User $actor, Role $role): bool
    {
        if ($role->key === User::SUPER_ADMIN) {
            return false;
        }

        return $actor->isSuperAdmin() || $actor->isPlatformManager() || $role->rank < $actor->highestRank();
    }

    /** Attribuer (ou retirer) un role : Pasteur Resident par un Pasteur Resident seulement ; sinon rang inferieur. */
    private function canGrantRole(User $actor, Role $role): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }
        if ($role->key === User::SUPER_ADMIN) {
            return false;
        }

        return $actor->isPlatformManager() || $role->rank < $actor->highestRank();
    }

    private function permissionIds(array $keys): array
    {
        return Permission::whereIn('key', $keys)->pluck('id')->all();
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'role';
        $key = $base;
        $i = 2;
        while (Role::where('key', $key)->exists()) {
            $key = "{$base}_{$i}";
            $i++;
        }

        return $key;
    }

    /**
     * Le compte de gestion principal n'a pas de role (ses droits n'en dependent pas) : aucune attribution
     * ni retrait ne le vise. Une tentative est consignee dans le journal de securite.
     */
    private function ensureOrdinaryAccount(User $target, User $actor, string $what): void
    {
        if ($target->isPlatformManager() || PlatformManager::isReservedPhone($target->phone)) {
            SecurityJournal::record('manager.role_'.$what.'_refused', ['target_user_id' => $target->id], 'warning', $actor);
            abort(404, 'Compte introuvable.');
        }
    }

    /** Attribuer un role a un membre (avec portee si le role l'exige). */
    public function assign(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_key' => ['required', 'exists:roles,key'],
            'scope_id' => ['nullable', 'integer'],
        ]);

        $role = Role::where('key', $data['role_key'])->firstOrFail();
        $actor = $request->user();
        $this->ensureOrdinaryAccount($user, $actor, 'assign');

        // Anti-escalade de privileges : on ne peut pas attribuer un role aussi eleve (ou plus) que le sien.
        // Le Pasteur Resident fait tout ; le compte de gestion attribue tout role sauf celui de Pasteur Resident.
        if (! $this->canGrantRole($actor, $role)) {
            abort(403, $role->key === User::SUPER_ADMIN
                ? 'Seul un super administrateur peut attribuer ce rôle.'
                : 'Vous ne pouvez pas attribuer un rôle de niveau supérieur ou égal au vôtre.');
        }

        $scopeKind = null;
        $scopeId = null;

        if ($role->scope_kind !== 'none') {
            $scopeKind = $role->scope_kind;
            $scopeId = $data['scope_id'] ?? null;

            $valid = match ($scopeKind) {
                'tribe' => Tribe::whereKey($scopeId)->exists(),
                // Le GEM doit appartenir a la tribu du membre.
                'gem' => Gem::whereKey($scopeId)->where('tribe_id', $user->profile?->tribe_id)->exists(),
                // Fidele confie : un autre membre inscrit (jamais le compte de gestion).
                'member' => (int) $scopeId !== $user->id && User::whereKey($scopeId)->exceptPlatformManagers()
                    ->whereHas('profile', fn ($p) => $p->where('is_completed', true))->exists(),
                default => Department::whereKey($scopeId)->exists(),
            };

            if (! $scopeId || ! $valid) {
                $label = match ($scopeKind) {
                    'tribe' => 'tribu', 'gem' => 'GEM de sa tribu', 'member' => 'fidèle (autre que lui-même)', default => 'département'
                };
                throw ValidationException::withMessages([
                    'scope_id' => "Veuillez choisir un(e) {$label} valide.",
                ]);
            }
        }

        // Responsable de departement : memes conditions que dans Organisation (profil complet, 5 au plus).
        $leadsDepartment = DepartmentLeadership::isLeaderRole($role, $scopeKind);
        if ($leadsDepartment) {
            DepartmentLeadership::assertCanLead(Department::findOrFail($scopeId), $user->id);
        }

        $duplicate = DB::table('role_user')->where([
            'user_id' => $user->id, 'role_id' => $role->id,
            'scope_kind' => $scopeKind, 'scope_id' => $scopeId,
        ])->exists();

        if (! $duplicate) {
            Audit::log('role.assigned', $user, $user->id, [], ['role' => $role->key, 'scope_kind' => $scopeKind, 'scope_id' => $scopeId]);
            $user->roles()->attach($role->id, [
                'scope_kind' => $scopeKind,
                'scope_id' => $scopeId,
                'assigned_by' => $actor->id,
            ]);

            $scopeName = match ($scopeKind) {
                'tribe' => ' · tribu '.Tribe::find($scopeId)?->name,
                'gem' => ' · GEM '.Gem::find($scopeId)?->name,
                'department' => ' · '.Department::find($scopeId)?->name,
                'member' => ' · '.Profile::where('user_id', $scopeId)->first()?->full_name,
                default => '',
            };
            Notifier::send([$user->id], 'role', 'Nouvelle fonction : '.$role->name.$scopeName,
                'Une nouvelle fonction vous a été confiée. Que Dieu vous fortifie dans ce service !', '/tableau-de-bord');
        }

        // Nommer un Garde sur un GEM le designe aussitot comme responsable de ce GEM (un seul Garde par GEM).
        if ($scopeKind === 'gem' && $scopeId && $role->key === 'garde') {
            GemRules::appointLeader(Gem::findOrFail($scopeId), $user->id, $actor->id);
        }
        // Le role de responsable de departement le nomme aussitot responsable dans Organisation.
        if ($leadsDepartment) {
            DepartmentLeadership::appoint(Department::findOrFail($scopeId), $user->id);
        }

        return response()->json(['message' => 'Rôle attribué.']);
    }

    /** Retirer une attribution de role precise. */
    public function remove(Request $request, User $user, int $assignment): JsonResponse
    {
        $this->ensureOrdinaryAccount($user, $request->user(), 'revoke');
        $row = DB::table('role_user')->where('id', $assignment)->where('user_id', $user->id)->first();
        if (! $row) {
            abort(404, 'Attribution introuvable.');
        }

        $revoked = Role::find($row->role_id);
        if ($revoked && ! $this->canGrantRole($request->user(), $revoked)) {
            abort(403, $revoked->key === User::SUPER_ADMIN
                ? 'Seul un super administrateur peut retirer ce rôle.'
                : 'Vous ne pouvez pas retirer un rôle de niveau supérieur ou égal au vôtre.');
        }

        // Empeche de retirer son propre role de super admin (eviter de se verrouiller dehors).
        if ($revoked?->key === User::SUPER_ADMIN && $user->id === $request->user()->id) {
            abort(403, 'Vous ne pouvez pas retirer votre propre rôle de super administrateur.');
        }

        DB::table('role_user')->where('id', $assignment)->delete();
        Audit::log('role.revoked', $user, $user->id, ['role' => $revoked?->key, 'scope_kind' => $row->scope_kind, 'scope_id' => $row->scope_id]);

        // Retirer le role Garde d'un GEM : ce GEM n'a plus de responsable.
        if ($row->scope_kind === 'gem' && $row->scope_id && $revoked?->key === 'garde') {
            Gem::whereKey($row->scope_id)->where('leader_user_id', $user->id)->update(['leader_user_id' => null]);
        }
        // Retirer le role de responsable de departement : il n'est plus responsable dans Organisation.
        if ($row->scope_id && DepartmentLeadership::isLeaderRole($revoked, $row->scope_kind) && ($department = Department::find($row->scope_id))) {
            DepartmentLeadership::dismiss($department, $user->id);
        }

        return response()->json(['message' => 'Rôle retiré.']);
    }
}
