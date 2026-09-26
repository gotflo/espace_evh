<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Event;
use App\Services\CalendarService;
use App\Services\Notifier;
use App\Support\Recipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Servir : le fidele s'inscrit a un service (departement). L'inscription est immediate :
 * il integre le departement, recoit ses annonces / evenements / exercices, et les
 * responsables du departement sont prevenus automatiquement.
 */
class MyServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profile;
        $mine = $profile
            ? DB::table('department_profile')->where('profile_id', $profile->id)->pluck('created_at', 'department_id')
            : collect();

        $departments = Department::where('is_active', true)
            ->withCount('members')
            ->with('leaders.profile:id,user_id,first_name,last_name,photo_path')
            ->orderBy('name')->get();

        // Prochain evenement de chaque departement (sur 60 jours).
        $next = CalendarService::occurrences(
            Event::where('is_personal', false)->with('scopes')->whereHas('scopes', fn ($s) => $s->where('scope_type', 'department')->whereIn('scope_id', $departments->pluck('id'))),
            now(), now()->addDays(60)->endOfDay(),
        )->flatMap(fn ($o) => $o['event']->scopes->where('scope_type', 'department')->map(fn ($s) => ['dept' => $s->scope_id] + $o))
            ->groupBy('dept')->map(fn ($list) => $list->first());

        return response()->json([
            'services' => $departments->map(function (Department $d) use ($mine, $next) {
                $upcoming = $next->get($d->id);

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'description' => $d->description,
                    'members_count' => $d->members_count,
                    'leader' => $d->leaders->map(fn ($l) => $l->profile?->full_name)->filter()->implode(', ') ?: null,
                    'leader_photo_url' => $d->leaders->first()?->profile?->photo_url,
                    'joined' => $mine->has($d->id),
                    'joined_at' => $mine->get($d->id) ? substr((string) $mine->get($d->id), 0, 10) : null,
                    'next_event' => $upcoming ? [
                        'title' => $upcoming['event']->title,
                        'starts_at' => $upcoming['start']->toIso8601String(),
                    ] : null,
                ];
            })->values(),
        ]);
    }

    /** S'inscrire a un service : integration immediate dans le departement. */
    public function join(Request $request, Department $department): JsonResponse
    {
        abort_unless($department->is_active, 422, "Ce service n'accepte pas d'inscription pour le moment.");
        $user = $request->user();
        $profile = $user->profile;
        abort_unless($profile && $profile->is_completed, 422, "Complétez d'abord votre profil.");

        if ($profile->departments()->whereKey($department->id)->exists()) {
            return response()->json(['message' => "Vous servez déjà dans {$department->name}."]);
        }

        $profile->departments()->attach($department->id);

        $name = $profile->full_name ?: $user->phone;
        Notifier::send(
            array_diff(Recipients::departmentLeaders($department), [$user->id]),
            'service',
            "Nouveau serviteur : {$name}",
            "{$name} vient de s'inscrire au service {$department->name}. Prenez contact pour l'accueillir.",
            "/admin/membres/{$user->id}",
        );
        Notifier::send([$user->id], 'service', "Bienvenue au service {$department->name} 🙌",
            'Votre inscription est enregistrée. Vous recevrez désormais les annonces et les événements de ce département.',
            '/servir');

        return response()->json(['message' => "Bienvenue dans le service {$department->name} !"]);
    }

    /** Quitter un service. */
    public function leave(Request $request, Department $department): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profile;
        abort_unless($profile, 422);

        if ($profile->departments()->detach($department->id)) {
            $name = $profile->full_name ?: $user->phone;
            Notifier::send(
                array_diff(Recipients::departmentLeaders($department), [$user->id]),
                'service',
                "{$name} a quitté le service {$department->name}",
                null,
                "/admin/membres/{$user->id}",
            );
        }

        return response()->json(['message' => "Vous ne faites plus partie du service {$department->name}."]);
    }
}
