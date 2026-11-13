<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gem;
use App\Models\Profile;
use App\Services\GemReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mon GEM : le fidele voit qui compose son GEM (nom et photo seulement) et qui en est le Garde.
 * Aucun identifiant, numero ni courriel : la liste ne permet d'ouvrir la fiche de personne.
 */
class MyGemController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $me = $request->user();
        $gem = $me->profile?->gem_id ? Gem::with('tribe:id,name')->find($me->profile->gem_id) : null;
        if (! $gem) {
            return response()->json(['gem' => null]);
        }

        $gardeId = (int) ($gem->leader_user_id ?? 0);
        // Memes membres que le rapport de GEM (profils completes) ; le Garde y figure toujours, en tete.
        $members = GemReportService::members($gem);
        if ($gardeId && ! $members->contains('user_id', $gardeId)) {
            $garde = Profile::where('user_id', $gardeId)->first();
            if ($garde) {
                $members->prepend($garde);
            }
        }

        return response()->json([
            'gem' => [
                'label' => $gem->label(),
                'tribe' => $gem->tribe?->name,
                'members' => $members
                    ->sortBy(fn (Profile $p) => (int) $p->user_id === $gardeId ? 0 : 1, SORT_REGULAR)
                    ->map(fn (Profile $p) => [
                        'name' => $p->full_name,
                        'photo_url' => $p->photo_url,
                        'is_garde' => (int) $p->user_id === $gardeId,
                        'is_me' => (int) $p->user_id === (int) $me->id,
                    ])->values(),
            ],
        ]);
    }
}
