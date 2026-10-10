<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Support\MemberScope;
use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Nouveaux inscrits : suivi d'integration. Un inscrit reste dans la liste « a accueillir »
 * jusqu'a ce qu'un responsable le marque comme accueilli ; la liste ne garde que les
 * inscriptions des 30 derniers jours (elle se vide donc toute seule).
 */
class NewMemberController extends Controller
{
    public const WINDOW_DAYS = 30;

    /** Arrivee d'un membre : la validation par le pasteur (nouveaux comptes), sinon l'inscription. */
    public const ARRIVAL = 'COALESCE((SELECT u.approval_decided_at FROM users u WHERE u.id = profiles.user_id AND u.approval_status = \'approved\'), profiles.created_at)';

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
            'window_days' => PlatformSettings::int('new_members.window_days'),
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
            ->select('profiles.*')->selectRaw(self::ARRIVAL.' as arrived_at')
            ->whereRaw(self::ARRIVAL.' >= ?', [now()->subDays(PlatformSettings::int('new_members.window_days'))->startOfDay()->toDateTimeString()])
            ->where('profiles.user_id', '!=', $viewer->id)
            ->with(['tribe:id,name', 'welcomer.profile:id,user_id,first_name,last_name', 'user:id,phone'])
            ->orderByRaw(self::ARRIVAL.' desc');
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
            'registered_at' => self::arrivedAt($p)?->toIso8601String(),
            'days_ago' => ($at = self::arrivedAt($p)) ? (int) floor($at->copy()->startOfDay()->diffInDays(now()->startOfDay())) : null,
            'welcomed' => $p->welcomed_at !== null,
            'welcomed_at' => $p->welcomed_at?->toDateString(),
            'welcomed_by' => $p->welcomer?->profile?->full_name ?: null,
        ];
    }

    /** Date d'arrivee (validation du compte, sinon inscription). */
    public static function arrivedAt(Profile $p): ?Carbon
    {
        return $p->arrived_at ? Carbon::parse($p->arrived_at) : $p->created_at;
    }

    private function authorizeView(User $user): void
    {
        abort_unless($user->hasPermission('members.view_all') || $user->hasPermission('members.view_scope'), 403, 'Accès refusé.');
    }
}
