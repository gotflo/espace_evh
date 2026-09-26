<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Support\MemberScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nouveaux inscrits : suivi d'integration. Un inscrit reste dans la liste « a accueillir »
 * jusqu'a ce qu'un responsable le marque comme accueilli ; la liste ne garde que les
 * inscriptions des 30 derniers jours (elle se vide donc toute seule).
 */
class NewMemberController extends Controller
{
    public const WINDOW_DAYS = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeView($user);
        $filter = $request->query('filter') === 'all' ? 'all' : 'to_welcome';

        $query = self::recentQuery($user);
        if ($filter === 'to_welcome') {
            $query->whereNull('welcomed_at');
        }

        return response()->json([
            'window_days' => self::WINDOW_DAYS,
            'filter' => $filter,
            'members' => $query->limit(100)->get()->map(fn (Profile $p) => self::present($p))->values(),
            'counts' => self::counts($user),
        ]);
    }

    /** Marquer un nouvel inscrit comme accueilli (contacte / integre). */
    public function welcome(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeView($actor);
        abort_unless($actor->canManageMember($user) && $user->profile, 403, 'Hors de votre portée.');

        $user->profile->forceFill(['welcomed_at' => now(), 'welcomed_by' => $actor->id])->save();

        return response()->json(['message' => 'Marqué comme accueilli.', 'counts' => self::counts($actor)]);
    }

    /** Annuler (erreur de clic). */
    public function unwelcome(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->authorizeView($actor);
        abort_unless($actor->canManageMember($user) && $user->profile, 403, 'Hors de votre portée.');

        $user->profile->forceFill(['welcomed_at' => null, 'welcomed_by' => null])->save();

        return response()->json(['message' => 'Accueil annulé.', 'counts' => self::counts($actor)]);
    }

    /** Inscriptions recentes visibles par ce responsable, les plus recentes d'abord. */
    public static function recentQuery(User $viewer): Builder
    {
        return MemberScope::manageableProfiles(Profile::query(), $viewer)
            ->where('profiles.created_at', '>=', now()->subDays(self::WINDOW_DAYS)->startOfDay())
            ->where('profiles.user_id', '!=', $viewer->id)
            ->with(['tribe:id,name', 'welcomer.profile:id,user_id,first_name,last_name', 'user:id,phone'])
            ->orderByDesc('profiles.created_at');
    }

    /** @return array{to_welcome: int, recent: int} */
    public static function counts(User $viewer): array
    {
        $recent = self::recentQuery($viewer)->count();
        $toWelcome = self::recentQuery($viewer)->whereNull('welcomed_at')->count();

        return ['to_welcome' => $toWelcome, 'recent' => $recent];
    }

    /** @return array<string, mixed> */
    public static function present(Profile $p): array
    {
        return [
            'user_id' => $p->user_id,
            'full_name' => $p->full_name ?: '(profil incomplet)',
            'photo_url' => $p->photo_url,
            'phone' => $p->user?->phone,
            'tribe' => $p->tribe?->name,
            'is_completed' => (bool) $p->is_completed,
            'registered_at' => $p->created_at?->toIso8601String(),
            'days_ago' => $p->created_at ? (int) floor($p->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay())) : null,
            'welcomed' => $p->welcomed_at !== null,
            'welcomed_at' => $p->welcomed_at?->toDateString(),
            'welcomed_by' => $p->welcomer?->profile?->full_name ?: null,
        ];
    }

    private function authorizeView(User $user): void
    {
        abort_unless($user->hasPermission('members.view_all') || $user->hasPermission('members.view_scope'), 403, 'Accès refusé.');
    }
}
