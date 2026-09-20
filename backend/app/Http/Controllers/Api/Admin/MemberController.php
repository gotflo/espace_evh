<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Profile;
use App\Models\Tribe;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MemberController extends Controller
{
    /** Liste des membres, limitee a la portee de l'utilisateur (tribu/departement) si besoin. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $viewAll = $user->hasPermission('members.view_all');

        if (! $viewAll && ! $user->hasPermission('members.view_scope')) {
            abort(403, 'Accès refuse.');
        }

        $query = Profile::query()->with([
            'user' => fn ($q) => $q->with('roles')->withMax('attendances as last_attendance_date', 'attended_on'),
            'tribe', 'departments',
        ]);

        // Portee : responsables limites a leurs GEMs / tribus / departements.
        if (! $viewAll) {
            $gemIds = $user->scopeGemIds();
            [$tribeIds, $deptIds] = $this->scopeIds($user);
            $query->where(function ($sub) use ($gemIds, $tribeIds, $deptIds) {
                $sub->whereIn('gem_id', $gemIds ?: [0])
                    ->orWhereIn('tribe_id', $tribeIds ?: [0])
                    ->orWhereHas('departments', fn ($d) => $d->whereIn('departments.id', $deptIds ?: [0]));
            });
        }

        // Filtres
        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('phone', 'like', "%{$q}%"));
            });
        }
        if ($tribe = $request->query('tribe_id')) {
            $query->where('tribe_id', $tribe);
        }
        if ($dept = $request->query('department_id')) {
            $query->whereHas('departments', fn ($d) => $d->where('departments.id', $dept));
        }

        $members = $query->orderBy('last_name')->orderBy('first_name')->get()
            ->map(fn (Profile $p) => $this->listItem($p));

        // Filtre actif / inactif (statut calcule, donc filtre en memoire).
        $status = $request->query('status');
        if ($status === 'active' || $status === 'inactive') {
            $members = $members->where('activity', $status);
        }

        return response()->json([
            'members' => $members->values(),
            'total' => $members->count(),
        ]);
    }

    /** Fiche detaillee d'un membre. */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        if (! $viewer->canViewMember($user)) {
            abort(403, 'Accès refuse.');
        }

        $user->load(['profile.tribe', 'profile.gem', 'profile.departments', 'roles.permissions', 'spiritualProfile']);
        $lastSeen = $user->lastSeenAt();

        return response()->json([
            'user' => array_merge($user->only(['id', 'phone', 'last_login_at']), [
                'activity' => $user->activityStatus(),
                'activity_override' => $user->activity_override,
                'last_seen' => $lastSeen?->toDateString(),
            ]),
            'profile' => $user->profile,
            'spiritual_profile' => $user->spiritualProfile,
            'roles' => $user->roles->map(fn ($r) => [
                'assignment_id' => $r->pivot->id,
                'key' => $r->key,
                'name' => $r->name,
                'scope_kind' => $r->pivot->scope_kind,
                'scope_id' => $r->pivot->scope_id,
                'scope_name' => $this->scopeName($r->pivot->scope_kind, $r->pivot->scope_id),
            ])->values(),
        ]);
    }

    /**
     * Forcer manuellement le statut d'un membre, ou revenir au calcul automatique.
     * status = active | inactive | auto
     */
    public function setActivity(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canViewMember($user), 403, 'Accès refuse.');

        $data = $request->validate(['status' => ['required', 'in:active,inactive,auto']]);

        $user->update(['activity_override' => $data['status'] === 'auto' ? null : $data['status']]);

        return response()->json([
            'message' => 'Statut mis à jour.',
            'activity' => $user->activityStatus(),
            'activity_override' => $user->activity_override,
        ]);
    }

    /** Assigner la tribu et les departements d'un membre (appartenance). */
    public function updateBelonging(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canViewMember($user), 403, 'Accès refuse.');

        $data = $request->validate([
            'tribe_id' => ['nullable', 'exists:tribes,id'],
            'gem_id' => ['nullable', 'exists:gems,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);

        $profile = $user->profile;
        if (! $profile) {
            abort(404, 'Profil introuvable.');
        }

        $profile->update(['tribe_id' => $data['tribe_id'] ?? null, 'gem_id' => $data['gem_id'] ?? null]);
        $profile->departments()->sync($data['department_ids'] ?? []);

        return response()->json([
            'message' => 'Appartenance mise à jour.',
            'profile' => $profile->fresh()->load('tribe', 'gem', 'departments'),
        ]);
    }

    /** Ids des tribus/departements sur lesquels l'utilisateur a une portee. */
    private function scopeIds(User $user): array
    {
        $tribeIds = $user->roles->where('pivot.scope_kind', 'tribe')
            ->pluck('pivot.scope_id')->filter()->unique()->values()->all();
        $deptIds = $user->roles->where('pivot.scope_kind', 'department')
            ->pluck('pivot.scope_id')->filter()->unique()->values()->all();

        return [$tribeIds, $deptIds];
    }

    private function scopeName(?string $kind, ?int $id): ?string
    {
        if (! $kind || ! $id) {
            return null;
        }

        return match ($kind) {
            'tribe' => Tribe::find($id)?->name,
            'gem' => \App\Models\Gem::find($id)?->name,
            default => Department::find($id)?->name,
        };
    }

    private function listItem(Profile $p): array
    {
        $u = $p->user;
        $lastSeen = $this->lastSeenFromEager($u);

        return [
            'user_id' => $p->user_id,
            'full_name' => $p->full_name ?: '(profil incomplet)',
            'phone' => $u?->phone,
            'photo_url' => $p->photo_url,
            'tribe' => $p->tribe?->name,
            'departments' => $p->departments->pluck('name')->values(),
            'is_completed' => (bool) $p->is_completed,
            'activity' => User::activityFrom($u?->activity_override, $lastSeen),
            'last_seen' => $lastSeen?->toDateString(),
            'roles' => $u ? $u->roles->pluck('name')->values() : [],
        ];
    }

    /** Derniere fois vu, a partir du user charge avec withMax('attendances'). */
    private function lastSeenFromEager(?User $u): ?Carbon
    {
        if (! $u) {
            return null;
        }
        $dates = array_filter([
            $u->last_attendance_date ? Carbon::parse($u->last_attendance_date) : null,
            $u->last_login_at,
        ]);

        return empty($dates) ? null : collect($dates)->max();
    }
}
