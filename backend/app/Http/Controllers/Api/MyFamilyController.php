<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FamilyLink;
use App\Services\FamilyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Famille du membre : conjoint(e), enfants, liens a confirmer. */
class MyFamilyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(FamilyService::overview($request->user()));
    }

    /** Recherche d'un membre (conjoint ou enfant) : nom, tribu, photo ; jamais de coordonnees. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);

        return response()->json(['results' => FamilyService::search($request->user(), $data['q'])->values()]);
    }

    public function setSpouse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['nullable', 'string', 'max:150'],
        ]);
        FamilyService::setSpouse($request->user(), isset($data['user_id']) ? (int) $data['user_id'] : null, $data['name'] ?? null);

        return response()->json([
            'message' => isset($data['user_id']) ? 'Conjoint(e) indiqué(e) : en attente de sa confirmation.' : 'Informations du conjoint enregistrées.',
            'family' => FamilyService::overview($request->user()),
        ]);
    }

    public function setChildren(Request $request): JsonResponse
    {
        $data = $request->validate([
            'has_children' => ['required', 'boolean'],
            'children' => ['array', 'max:20'],
            'children.*.name' => ['required', 'string', 'min:2', 'max:150'],
            'children.*.birth_year' => ['nullable', 'integer', 'min:1900', 'max:'.now()->year],
            'children.*.user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        FamilyService::setChildren($request->user(), (bool) $data['has_children'], $data['children'] ?? []);

        return response()->json(['message' => 'Enfants enregistrés.', 'family' => FamilyService::overview($request->user())]);
    }

    public function confirm(Request $request, FamilyLink $link): JsonResponse
    {
        FamilyService::confirm($request->user(), $link);

        return response()->json(['message' => 'Lien familial confirmé.', 'family' => FamilyService::overview($request->user())]);
    }

    public function decline(Request $request, FamilyLink $link): JsonResponse
    {
        FamilyService::decline($request->user(), $link);

        return response()->json(['message' => 'Lien refusé.', 'family' => FamilyService::overview($request->user())]);
    }
}
