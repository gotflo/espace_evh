<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gem;
use App\Models\Tribe;
use App\Models\User;
use App\Models\Profile;
use App\Support\GemRules;
use App\Support\LeaderRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** Tribus que l'utilisateur peut gerer : null si toutes (view_all/PA), sinon ses tribus. */
    private function allowedTribeIds(User $user): ?array
    {
        return $user->hasPermission('members.view_all') ? null : $user->scopeTribeIds();
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

        $members = Profile::where('tribe_id', $data['tribe_id'])->where('is_completed', true)
            ->with('gem:id,name')->orderBy('first_name')->orderBy('last_name')->get()
            ->map(fn (Profile $p) => [
                'user_id' => $p->user_id,
                'full_name' => $p->full_name,
                'photo_url' => $p->photo_url,
                'gem' => $p->gem?->name,
                'leads' => Gem::where('leader_user_id', $p->user_id)->pluck('name')->values(),
            ]);

        return response()->json(['members' => $members]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $leader = $data['leader_user_id'] ?? null;
        unset($data['leader_user_id']);
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

        // Changer la tribu d'un GEM qui a deja des membres d'une autre tribu casserait la regle.
        if ((int) $data['tribe_id'] !== (int) $gem->tribe_id
            && Profile::where('gem_id', $gem->id)->where('tribe_id', '!=', $data['tribe_id'])->exists()) {
            abort(422, 'Ce GEM contient des membres de sa tribu actuelle : impossible de le déplacer vers une autre tribu.');
        }

        $gem->update($data);
        GemRules::appointLeader($gem, $leader, $request->user()->id);

        return response()->json(['message' => 'GEM mis à jour.']);
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
