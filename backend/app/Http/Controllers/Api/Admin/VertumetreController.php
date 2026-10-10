<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Models\VertumetreForm;
use App\Services\FissService;
use App\Services\VertumetreService;
use App\Support\VertumetreCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vertumetre, cote responsables : memes lecteurs que la FISS (pasteurs ; patriarche et AP de la tribu
 * du membre). Suivi du mois (qui l'a rempli) et reponses d'un membre.
 */
class VertumetreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $period = $data['period'] ?? now()->format('Y-m');
        $tribes = $request->user()->fissTribeIds();
        abort_if($tribes === [], 403, 'Le Vertumètre des membres est suivi par les pasteurs et les responsables de tribu.');

        $members = Profile::where('is_completed', true)
            ->when($tribes !== null, fn ($q) => $q->whereIn('tribe_id', $tribes ?: [0]))
            ->with('tribe:id,name')->orderBy('last_name')->orderBy('first_name')->limit(2000)
            ->get(['user_id', 'first_name', 'last_name', 'tribe_id', 'photo_path']);
        $filled = VertumetreForm::where('period', $period)->whereIn('user_id', $members->pluck('user_id'))->pluck('submitted_at', 'user_id');

        $list = $members->map(fn (Profile $p) => [
            'user_id' => $p->user_id,
            'name' => $p->full_name,
            'tribe' => $p->tribe?->name,
            'photo_url' => $p->photo_url,
            'filled' => isset($filled[$p->user_id]),
        ])->values();

        return response()->json([
            'period' => $period,
            'period_label' => FissService::periodLabel($period),
            'members' => $list,
            'filled' => $list->where('filled', true)->count(),
            'total' => $list->count(),
        ]);
    }

    public function member(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canViewMember($user) && $viewer->canViewFissOf($user), 403,
            "Le Vertumètre d'un membre est réservé aux pasteurs et aux responsables de sa tribu.");

        return response()->json([
            'sections' => VertumetreCatalog::forClient(),
            'forms' => VertumetreForm::where('user_id', $user->id)->orderByDesc('period')->limit(12)->get()
                ->map(fn ($f) => VertumetreService::present($f, false))->values(),
        ]);
    }
}
