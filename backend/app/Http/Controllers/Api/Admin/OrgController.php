<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Audit;
use App\Support\DepartmentLeadership;
use App\Support\Like;
use App\Support\PlatformManager;
use App\Support\RoleGrants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrgController extends Controller
{
    /** Tribus et departements avec le nombre de membres. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('tribes.manage') && ! $user->hasPermission('departments.manage')) {
            abort(403, 'Accès refusé.');
        }

        return response()->json([
            // Membres comptes : profils completes seulement (comme partout ailleurs).
            'tribes' => Tribe::withCount(['members' => fn ($q) => $q->where('is_completed', true)])->orderBy('name')->get()
                ->map(fn (Tribe $t) => ['id' => $t->id, 'name' => $t->name, 'members_count' => $t->members_count]),
            'departments' => Department::withCount(['members' => fn ($q) => $q->where('profiles.is_completed', true)])
                ->with('leaders.profile:id,user_id,first_name,last_name')->orderBy('name')->get()
                ->map(fn (Department $d) => [
                    'id' => $d->id, 'name' => $d->name, 'members_count' => $d->members_count, 'tracks_rehearsal' => $d->tracks_rehearsal,
                    'description' => $d->description, 'is_active' => $d->is_active,
                    'leaders' => $d->leaders->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->profile?->full_name ?: $u->phone])->values(),
                ]),
        ]);
    }

    public function storeTribe(Request $request): JsonResponse
    {
        $name = $this->validateName($request);
        Tribe::create(['name' => $name, 'slug' => $this->uniqueSlug(Tribe::class, $name)]);

        return response()->json(['message' => 'Tribu créée.']);
    }

    public function updateTribe(Request $request, Tribe $tribe): JsonResponse
    {
        $tribe->update(['name' => $this->validateName($request)]);

        return response()->json(['message' => 'Tribu renommée.']);
    }

    /**
     * Suppression d'une tribu (ses GEMs disparaissent avec elle) : les roles qui y etaient rattaches
     * (patriarche, AP, gardes de ses GEMs) et les portees de publication qui la visaient sont retires,
     * pour qu'aucun droit ni aucune audience ne pointe vers une tribu disparue. Trace dans le journal.
     */
    public function destroyTribe(Tribe $tribe): JsonResponse
    {
        $gemIds = Gem::where('tribe_id', $tribe->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $scoped = fn (string $kindColumn) => fn ($q) => $q
            ->where(fn ($t) => $t->where($kindColumn, 'tribe')->where('scope_id', $tribe->id))
            ->orWhere(fn ($g) => $g->where($kindColumn, 'gem')->whereIn('scope_id', $gemIds ?: [0]));

        DB::transaction(function () use ($tribe, $gemIds, $scoped) {
            $roles = DB::table('role_user')->where($scoped('scope_kind'));
            Audit::log('tribe.deleted', $tribe, null, ['name' => $tribe->name], [], [
                'member_profile_ids' => $tribe->members()->pluck('id'),
                'gem_ids' => $gemIds,
                'role_holders' => (clone $roles)->pluck('user_id')->unique()->values(),
            ]);
            $roles->delete();
            DB::table('publication_scopes')->where($scoped('scope_type'))->delete();
            $tribe->delete();
        });

        return response()->json(['message' => 'Tribu supprimée.']);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $name = $this->validateName($request);
        Department::create(['name' => $name, 'slug' => $this->uniqueSlug(Department::class, $name)]);

        return response()->json(['message' => 'Département créé.']);
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'tracks_rehearsal' => ['sometimes', 'boolean'],
        ]);
        $department->update($data);

        return response()->json(['message' => 'Département mis à jour.']);
    }

    /**
     * Responsables d'un departement : ils recoivent les droits de responsable sur ce departement
     * (voir ses membres, faire l'appel des repetitions, publier ses evenements). Ils doivent en etre membres.
     */
    public function setDepartmentLeaders(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:5'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'])));
        // Nommer (ou retirer) un responsable lui donne (ou retire) le role de responsable de departement :
        // memes regles que pour attribuer ce role (rang, acces confidentiels detenus), voir RoleGrants.
        $current = $department->leaders()->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $role = Role::where('key', DepartmentLeadership::ROLE)->first();
        if ($role && $current !== collect($ids)->sort()->values()->all() && ($refusal = RoleGrants::assignRefusal($request->user(), $role))) {
            abort(403, $refusal);
        }
        // Tout membre de l'eglise (profil complet) peut etre nomme ; s'il ne fait pas encore partie
        // du departement, il y est ajoute (un nouveau departement peut ainsi recevoir ses responsables).
        $profiles = Profile::whereIn('user_id', $ids)->where('is_completed', true)->pluck('id', 'user_id');
        abort_if($profiles->count() !== count($ids), 422, 'Un responsable doit avoir un profil complet.');
        $joining = array_diff($profiles->values()->all(), $department->members()->pluck('profiles.id')->all());
        if ($joining) {
            $department->members()->syncWithoutDetaching($joining);
            Audit::log('department.members_added', $department, null, [], ['profile_ids' => array_values($joining)], ['reason' => 'nomme responsable']);
        }
        $before = $department->leaders()->pluck('users.id')->sort()->values()->all();
        $department->leaders()->sync($ids);
        Audit::log('department.leaders_updated', $department, null, ['leaders' => $before], ['leaders' => $ids]);
        // Le role « Responsable de departement » suit la liste (attribue aux nouveaux, retire aux anciens).
        DepartmentLeadership::syncRole($department, $before, $ids, $request->user()->id);
        foreach (array_diff($ids, $before) as $newLeader) {
            Notifier::send([$newLeader], 'role', "Responsable du département {$department->name}",
                'Vous pouvez désormais suivre ses membres, faire l\'appel des répétitions et publier ses événements.', '/tableau-de-bord');
        }

        return response()->json(['message' => 'Responsables du département mis à jour.']);
    }

    /**
     * Recherche d'un responsable a nommer (y compris par le compte de gestion, qui ne voit pas les fiches
     * des membres) : au plus 8 noms de membres au profil complet, compte valide et non bloque. Rien d'autre
     * (ni telephone ni autre donnee).
     */
    public function departmentCandidates(Request $request, Department $department): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q'];
        $words = array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);

        $query = Profile::where('is_completed', true)
            ->whereHas('user', fn ($u) => $u->exceptPlatformManagers()->where('approval_status', User::APPROVED)->whereNull('blocked_at'))
            ->whereNotIn('user_id', PlatformManager::userIds() ?: [0]);
        // Chaque mot dans le prenom ou le nom (« Marie Tremb » trouve Marie Tremblay).
        foreach ($words as $word) {
            $query->where(function ($w) use ($word) {
                Like::contains($w, 'first_name', $word);
                Like::contains($w, 'last_name', $word, 'or');
            });
        }

        return response()->json(['candidates' => $query->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(8)
            ->get(['user_id', 'first_name', 'last_name'])
            ->map(fn (Profile $p) => ['user_id' => $p->user_id, 'name' => trim($p->first_name.' '.$p->last_name)])->values()]);
    }

    /** Membres d'un departement (pour choisir ses responsables). */
    public function departmentMembers(Department $department): JsonResponse
    {
        return response()->json(['members' => $department->members()->orderBy('first_name')->get(['profiles.user_id', 'first_name', 'last_name'])
            ->map(fn ($p) => ['user_id' => $p->user_id, 'name' => trim($p->first_name.' '.$p->last_name)])->values()]);
    }

    public function destroyDepartment(Department $department): JsonResponse
    {
        Audit::log('department.deleted', $department, null, ['name' => $department->name], [], ['member_profile_ids' => $department->members()->pluck('profiles.id')]);
        DepartmentLeadership::forget($department);
        $department->delete();

        return response()->json(['message' => 'Département supprimé.']);
    }

    private function validateName(Request $request): string
    {
        return $request->validate(['name' => ['required', 'string', 'max:60']])['name'];
    }

    /** @param class-string<Model> $model */
    private function uniqueSlug(string $model, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;
        while ($model::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
