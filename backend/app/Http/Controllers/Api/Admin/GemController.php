<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use App\Support\Audit;
use App\Support\GemRules;
use App\Support\LeaderRole;
use App\Support\RoleGrants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GemController extends Controller
{
    /** Liste des GEMs (avec tribu, Garde, nombre de membres), limitee a la portee. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $scopedTribes = $this->allowedTribeIds($user); // null = toutes

        $gemsQuery = Gem::with('tribe', 'leader.profile')->withCount('members')->orderBy('name');
        $tribesQuery = Tribe::orderBy('name');
        if ($scopedTribes !== null) {
            $gemsQuery->whereIn('tribe_id', $scopedTribes ?: [0]);
            $tribesQuery->whereIn('id', $scopedTribes ?: [0]);
        }

        return response()->json([
            'gems' => $gemsQuery->get()->map(fn (Gem $g) => $this->present($g)),
            'tribes' => $tribesQuery->get(['id', 'name']),
        ]);
    }

    /**
     * Tribus dont l'utilisateur compose les GEMs : null si toutes (autorite pastorale qui gere les GEMs sans portee,
     * compte de gestion qui organise toute l'eglise), sinon les tribus ou il a gems.manage (patriarche, AP). Les autres
     * roles (responsable de departement, Garde...) n'ouvrent aucune tribu ici.
     */
    private function allowedTribeIds(User $user): ?array
    {
        if ($user->isPlatformManager() || $user->isChurchWideFor('gems.manage')) {
            return null;
        }

        return $user->scopesFor('gems.manage')['tribe'];
    }

    /**
     * Candidats Garde : uniquement les membres de la tribu du GEM (profil complete),
     * avec leur GEM actuel pour aider au choix.
     */
    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate(['tribe_id' => ['required', 'integer', 'exists:tribes,id']]);
        $allowed = $this->allowedTribeIds($request->user());
        abort_if($allowed !== null && ! in_array((int) $data['tribe_id'], array_map('intval', $allowed), true), 403, 'Hors de votre tribu.');

        $profiles = Profile::where('tribe_id', $data['tribe_id'])->where('is_completed', true)
            ->with('gem:id,name')->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'user_id', 'first_name', 'last_name', 'photo_path', 'gem_id']);
        // GEM dirige par chaque membre : une seule lecture (et non une par membre).
        $leads = Gem::whereNotNull('leader_user_id')->whereIn('leader_user_id', $profiles->pluck('user_id'))
            ->orderBy('id')->get(['id', 'leader_user_id', 'name'])->groupBy('leader_user_id');

        return response()->json(['members' => $profiles->map(fn (Profile $p) => [
            'user_id' => $p->user_id,
            'full_name' => $p->full_name,
            'photo_url' => $p->photo_url,
            'gem_id' => $p->gem_id,
            'gem' => $p->gem?->name,
            'leads' => $leads->get($p->user_id, collect())->pluck('name')->values(),
            'lead_gem_ids' => $leads->get($p->user_id, collect())->pluck('id')->map(fn ($id) => (int) $id)->values(),
        ])]);
    }

    /**
     * Composition d'un GEM (page GEMs) : la liste complete de ses membres. Seuls des membres de la tribu du GEM ; un
     * membre venant d'un autre GEM y est deplace ; le Garde reste toujours dans son GEM ; le Garde d'un autre GEM n'est
     * pas deplace. Chaque changement est trace dans le journal.
     */
    public function members(Request $request, Gem $gem): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->canManage($actor, $gem), 403, 'Ce GEM est hors de votre tribu.');
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:300'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);
        $wanted = collect($data['user_ids'])->map(fn ($id) => (int) $id);
        if ($gem->leader_user_id) {
            $wanted->push((int) $gem->leader_user_id);
        }
        $wanted = $wanted->unique()->values();

        $profiles = Profile::whereIn('user_id', $wanted)->get()->keyBy('user_id');
        foreach ($wanted as $id) {
            $p = $profiles->get($id);
            if (! $p || ! $p->is_completed || (int) $p->tribe_id !== (int) $gem->tribe_id) {
                throw ValidationException::withMessages(['user_ids' => ($p?->full_name ?: 'Ce membre')." n'appartient pas à la tribu de ce GEM."]);
            }
        }
        $current = Profile::where('gem_id', $gem->id)->pluck('user_id')->map(fn ($id) => (int) $id);
        $added = $wanted->diff($current)->values();
        $removed = $current->diff($wanted)->values();

        // Le Garde d'un autre GEM reste dans le sien.
        foreach (Gem::where('id', '!=', $gem->id)->whereIn('leader_user_id', $added)->get() as $other) {
            $p = $profiles->get((int) $other->leader_user_id);
            throw ValidationException::withMessages(['user_ids' => "{$p?->full_name} est Garde du GEM {$other->name} : il ou elle reste dans son GEM."]);
        }

        DB::transaction(function () use ($gem, $added, $removed, $profiles, $actor) {
            foreach ($added as $id) {
                $p = $profiles->get($id);
                $old = $p->gem_id;
                $p->forceFill(['gem_id' => $gem->id])->save();
                Audit::log('member.belonging_updated', $p, $id, ['gem_id' => $old], ['gem_id' => $gem->id], ['by' => 'gem_page'], $actor);
            }
            foreach (Profile::whereIn('user_id', $removed)->get() as $p) {
                $p->forceFill(['gem_id' => null])->save();
                Audit::log('member.belonging_updated', $p, (int) $p->user_id, ['gem_id' => $gem->id], ['gem_id' => null], ['by' => 'gem_page'], $actor);
            }
        });

        return response()->json([
            'message' => $added->isEmpty() && $removed->isEmpty() ? 'Aucun changement.' : 'Membres du GEM enregistrés.',
            'added' => $added->count(), 'removed' => $removed->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $leader = $data['leader_user_id'] ?? null;
        unset($data['leader_user_id']);
        $this->ensureCanAppointGarde($request->user(), $leader, null);
        $gem = Gem::create($data);
        // Le responsable nomme recoit aussitot le role Garde sur ce GEM (et le rejoint).
        GemRules::appointLeader($gem, $leader, $request->user()->id);

        return response()->json(['message' => 'GEM créé.', 'gem' => $this->present($gem->load('tribe', 'leader.profile')->loadCount('members'))], 201);
    }

    public function update(Request $request, Gem $gem): JsonResponse
    {
        abort_unless($this->canManage($request->user(), $gem), 403, 'Ce GEM est hors de votre tribu.');
        $data = $this->validated($request);
        $leader = $data['leader_user_id'] ?? null;
        unset($data['leader_user_id']);
        $this->ensureCanAppointGarde($request->user(), $leader, $gem->leader_user_id);

        // Changer la tribu d'un GEM qui a deja des membres d'une autre tribu casserait la regle.
        if ((int) $data['tribe_id'] !== (int) $gem->tribe_id
            && Profile::where('gem_id', $gem->id)->where('tribe_id', '!=', $data['tribe_id'])->exists()) {
            abort(422, 'Ce GEM contient des membres de sa tribu actuelle : impossible de le déplacer vers une autre tribu.');
        }

        $gem->update($data);
        GemRules::appointLeader($gem, $leader, $request->user()->id);

        return response()->json(['message' => 'GEM mis à jour.']);
    }

    /**
     * Nommer (ou retirer) un Garde lui donne (ou retire) le role Garde : memes regles que pour attribuer
     * ce role (rang, acces confidentiels detenus), voir RoleGrants.
     */
    private function ensureCanAppointGarde(User $actor, mixed $leader, mixed $current): void
    {
        if ((int) $leader === (int) $current) {
            return;
        }
        $role = Role::where('key', 'garde')->first();
        if ($role && ($refusal = RoleGrants::assignRefusal($actor, $role))) {
            abort(403, $refusal);
        }
    }

    public function destroy(Request $request, Gem $gem): JsonResponse
    {
        abort_unless($this->canManage($request->user(), $gem), 403, 'Ce GEM est hors de votre tribu.');
        // Retire le role Garde lie a ce GEM avant suppression.
        LeaderRole::sync('garde', 'gem', $gem->id, null, $request->user()->id);
        $gem->delete();

        return response()->json(['message' => 'GEM supprimé.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'tribe_id' => ['required', 'exists:tribes,id'],
            'leader_user_id' => ['nullable', 'exists:users,id'],
        ]);
        // Un responsable de tribu ne peut creer un GEM que dans sa propre tribu.
        $allowed = $this->allowedTribeIds($request->user());
        if ($allowed !== null && ! in_array((int) $data['tribe_id'], array_map('intval', $allowed), true)) {
            abort(403, 'Vous ne pouvez créer un GEM que dans votre tribu.');
        }
        // Le Garde doit deja appartenir a la tribu du GEM.
        GemRules::assertLeaderInTribe(isset($data['leader_user_id']) ? (int) $data['leader_user_id'] : null, (int) $data['tribe_id']);

        return $data;
    }

    private function canManage(User $user, Gem $gem): bool
    {
        $allowed = $this->allowedTribeIds($user);

        return $allowed === null || in_array((int) $gem->tribe_id, array_map('intval', $allowed), true);
    }

    /** @return array<string, mixed> */
    private function present(Gem $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'tribe_id' => $g->tribe_id,
            'tribe' => $g->tribe?->name,
            'leader_user_id' => $g->leader_user_id,
            'leader' => $g->leader?->profile?->full_name ?: null,
            'members_count' => $g->members_count ?? 0,
        ];
    }
}
