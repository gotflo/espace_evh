<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    /** Liste des roles (pour le menu d'attribution ET la gestion des roles). */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,key')->orderByDesc('rank')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'key' => $r->key,
                'name' => $r->name,
                'description' => $r->description,
                'scope_kind' => $r->scope_kind, // none | tribe | department
                'is_system' => $r->is_system,
                'permission_keys' => $r->permissions->pluck('key')->values(),
            ]);

        return response()->json(['roles' => $roles]);
    }

    /** Catalogue des permissions, groupees, pour construire un role. */
    public function permissions(): JsonResponse
    {
        $groups = Permission::orderBy('id')->get()
            ->groupBy('group')
            ->map(fn ($items, $group) => [
                'group' => $group,
                'permissions' => $items->map(fn ($p) => ['key' => $p->key, 'name' => $p->name])->values(),
            ])->values();

        return response()->json(['groups' => $groups]);
    }

    /** Creer un role personnalise. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRole($request);

        $role = Role::create([
            'key' => $this->uniqueKey($data['name']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope_kind' => $data['scope_kind'],
            'rank' => 30, // entre les fonctions et fidele, ajustable
            'is_system' => false,
        ]);
        $role->permissions()->sync($this->permissionIds($data['permission_keys'] ?? []));

        return response()->json(['message' => 'Rôle créé.', 'role_id' => $role->id]);
    }

    /** Modifier un role (personnalise ou de base : permissions/nom/description). */
    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $this->validateRole($request);

        $payload = ['name' => $data['name'], 'description' => $data['description'] ?? null];
        // On ne change pas la portee des roles de base (structure figee).
        if (! $role->is_system) {
            $payload['scope_kind'] = $data['scope_kind'];
        }
        $role->update($payload);
        $role->permissions()->sync($this->permissionIds($data['permission_keys'] ?? []));

        return response()->json(['message' => 'Rôle mis à jour.']);
    }

    /** Supprimer un role personnalise (les roles de base sont proteges). */
    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            abort(403, 'Les rôles de base ne peuvent pas être supprimés.');
        }
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
            'permission_keys.*' => ['string', 'exists:permissions,key'],
        ]);
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

    /** Attribuer un role a un membre (avec portee si le role l'exige). */
    public function assign(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_key' => ['required', 'exists:roles,key'],
            'scope_id' => ['nullable', 'integer'],
        ]);

        $role = Role::where('key', $data['role_key'])->firstOrFail();

        // Anti-escalade de privileges : on ne peut pas attribuer un role
        // aussi eleve (ou plus) que le sien. Seul un super admin fait tout.
        $actor = $request->user();
        if (! $actor->isSuperAdmin()) {
            if ($role->key === User::SUPER_ADMIN) {
                abort(403, 'Seul un super administrateur peut attribuer ce rôle.');
            }
            if ($role->rank >= $actor->highestRank()) {
                abort(403, 'Vous ne pouvez pas attribuer un rôle de niveau supérieur ou égal au vôtre.');
            }
        }

        $scopeKind = null;
        $scopeId = null;

        if ($role->scope_kind !== 'none') {
            $scopeKind = $role->scope_kind;
            $scopeId = $data['scope_id'] ?? null;

            $valid = match ($scopeKind) {
                'tribe' => Tribe::whereKey($scopeId)->exists(),
                // Le GEM doit appartenir a la tribu du membre.
                'gem' => \App\Models\Gem::whereKey($scopeId)->where('tribe_id', $user->profile?->tribe_id)->exists(),
                // Fidele confie : un autre membre ayant un profil.
                'member' => (int) $scopeId !== $user->id && User::whereKey($scopeId)->whereHas('profile')->exists(),
                default => Department::whereKey($scopeId)->exists(),
            };

            if (! $scopeId || ! $valid) {
                $label = match ($scopeKind) { 'tribe' => 'tribu', 'gem' => 'GEM de sa tribu', 'member' => 'fidèle (autre que lui-même)', default => 'département' };
                throw ValidationException::withMessages([
                    'scope_id' => "Veuillez choisir un(e) {$label} valide.",
                ]);
            }
        }

        $duplicate = DB::table('role_user')->where([
            'user_id' => $user->id, 'role_id' => $role->id,
            'scope_kind' => $scopeKind, 'scope_id' => $scopeId,
        ])->exists();

        if (! $duplicate) {
            \App\Support\Audit::log('role.assigned', $user, $user->id, [], ['role' => $role->key, 'scope_kind' => $scopeKind, 'scope_id' => $scopeId]);
            $user->roles()->attach($role->id, [
                'scope_kind' => $scopeKind,
                'scope_id' => $scopeId,
                'assigned_by' => $request->user()->id,
            ]);

            $scopeName = match ($scopeKind) {
                'tribe' => ' · tribu '.Tribe::find($scopeId)?->name,
                'gem' => ' · GEM '.Gem::find($scopeId)?->name,
                'department' => ' · '.Department::find($scopeId)?->name,
                'member' => ' · '.\App\Models\Profile::where('user_id', $scopeId)->first()?->full_name,
                default => '',
            };
            Notifier::send([$user->id], 'role', 'Nouvelle fonction : '.$role->name.$scopeName,
                'Une nouvelle fonction vous a été confiée. Que Dieu vous fortifie dans ce service !', '/tableau-de-bord');
        }

        // Nommer un Garde sur un GEM le designe aussitot comme responsable de ce GEM (un seul Garde par GEM).
        if ($scopeKind === 'gem' && $scopeId && $role->key === 'garde') {
            \App\Support\GemRules::appointLeader(Gem::findOrFail($scopeId), $user->id, $request->user()->id);
        }

        return response()->json(['message' => 'Rôle attribué.']);
    }

    /** Retirer une attribution de role precise. */
    public function remove(Request $request, User $user, int $assignment): JsonResponse
    {
        $row = DB::table('role_user')->where('id', $assignment)->where('user_id', $user->id)->first();
        if (! $row) {
            abort(404, 'Attribution introuvable.');
        }

        // Empeche de retirer son propre role de super admin (eviter de se verrouiller dehors).
        $superAdmin = Role::where('key', User::SUPER_ADMIN)->first();
        if ($superAdmin && $row->role_id === $superAdmin->id && $user->id === $request->user()->id) {
            abort(403, 'Vous ne pouvez pas retirer votre propre rôle de super administrateur.');
        }

        DB::table('role_user')->where('id', $assignment)->delete();
        \App\Support\Audit::log('role.revoked', $user, $user->id, ['role' => Role::whereKey($row->role_id)->value('key'), 'scope_kind' => $row->scope_kind, 'scope_id' => $row->scope_id]);

        // Retirer le role Garde d'un GEM : ce GEM n'a plus de responsable.
        if ($row->scope_kind === 'gem' && $row->scope_id && Role::whereKey($row->role_id)->value('key') === 'garde') {
            Gem::whereKey($row->scope_id)->where('leader_user_id', $user->id)->update(['leader_user_id' => null]);
        }

        return response()->json(['message' => 'Rôle retiré.']);
    }
}
