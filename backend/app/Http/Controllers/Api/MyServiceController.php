<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\Event;
use App\Services\CalendarService;
use App\Services\DepartmentJoinService;
use App\Services\Notifier;
use App\Support\DepartmentLeadership;
use App\Support\Recipients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Servir : le fidele demande a rejoindre un service (departement) ; le responsable du departement valide
 * la demande (notification et courriel). Une fois valide, il recoit les annonces, evenements et exercices du
 * departement.
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
        $pending = DepartmentJoinRequest::where('user_id', $user->id)->where('status', 'pending')->pluck('created_at', 'department_id');

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
            'services' => $departments->map(function (Department $d) use ($mine, $next, $pending) {
                $upcoming = $next->get($d->id);

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'description' => $d->description,
                    'members_count' => $d->members_count,
                    'leader' => $d->leaders->map(fn ($l) => $l->profile?->full_name)->filter()->implode(', ') ?: null,
                    'leader_photo_url' => $d->leaders->first()?->profile?->photo_url,
                    'joined' => $mine->has($d->id),
                    // Demande envoyee, en attente du responsable du service.
                    'pending' => ! $mine->has($d->id) && $pending->has($d->id),
                    'joined_at' => $mine->get($d->id) ? substr((string) $mine->get($d->id), 0, 10) : null,
                    'next_event' => $upcoming ? [
                        'title' => $upcoming['event']->title,
                        'starts_at' => $upcoming['start']->toIso8601String(),
                    ] : null,
                ];
            })->values(),
        ]);
    }

    /** Demande d'inscription a un service : le responsable du departement la valide. */
    public function join(Request $request, Department $department): JsonResponse
    {
        $joinRequest = DepartmentJoinService::request($request->user(), $department);
        if (! $joinRequest) {
            return response()->json(['message' => "Vous servez déjà dans {$department->name}.", 'status' => 'member']);
        }

        return response()->json([
            'message' => "Demande envoyée : le responsable du service {$department->name} va la valider.",
            'status' => 'pending',
        ]);
    }

    /** Quitter un service. */
    public function leave(Request $request, Department $department): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profile;
        abort_unless($profile, 422);

        // Demande encore en attente : elle est simplement retiree.
        if (DepartmentJoinService::cancel($user, $department)) {
            return response()->json(['message' => "Votre demande pour le service {$department->name} est retirée."]);
        }

        if ($profile->departments()->detach($department->id)) {
            DepartmentLeadership::leave($department, $user->id, $user->id);
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
