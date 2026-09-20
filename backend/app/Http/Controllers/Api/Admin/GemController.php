<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gem;
use App\Models\Tribe;
use App\Models\User;
use App\Support\LeaderRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GemController extends Controller
{
    /** Liste des GEMs (avec tribu, GAD, nombre de membres), limitee a la portee. */
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

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $gem = Gem::create($data);
        // Le responsable nomme recoit aussitot le role GAD sur ce GEM.
        LeaderRole::sync('gad', 'gem', $gem->id, $gem->leader_user_id, $request->user()->id);

        return response()->json(['message' => 'GEM cree.', 'gem' => $this->present($gem->load('tribe', 'leader.profile')->loadCount('members'))], 201);
    }

    public function update(Request $request, Gem $gem): JsonResponse
    {
        $gem->update($this->validated($request));
        LeaderRole::sync('gad', 'gem', $gem->id, $gem->leader_user_id, $request->user()->id);

        return response()->json(['message' => 'GEM mis a jour.']);
    }

    public function destroy(Request $request, Gem $gem): JsonResponse
    {
        // Retire le role GAD lie a ce GEM avant suppression.
        LeaderRole::sync('gad', 'gem', $gem->id, null, $request->user()->id);
        $gem->delete();

        return response()->json(['message' => 'GEM supprime.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'tribe_id' => ['required', 'exists:tribes,id'],
            'leader_user_id' => ['nullable', 'exists:users,id'],
        ]);
        // Le GAD doit avoir un profil (etre un membre).
        if (! empty($data['leader_user_id']) && ! User::whereKey($data['leader_user_id'])->whereHas('profile')->exists()) {
            abort(422, 'Le responsable choisi doit etre un membre.');
        }
        // Un responsable de tribu ne peut creer un GEM que dans sa propre tribu.
        $allowed = $this->allowedTribeIds($request->user());
        if ($allowed !== null && ! in_array((int) $data['tribe_id'], $allowed, true)) {
            abort(403, 'Vous ne pouvez creer un GEM que dans votre tribu.');
        }

        return $data;
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
