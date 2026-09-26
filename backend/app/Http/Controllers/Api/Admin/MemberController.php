<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Services\FamilyService;
use App\Support\Audit;
use App\Support\GemRules;
use App\Support\MemberScope;
use App\Support\ProfileCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MemberController extends Controller
{
    /**
     * Liste des membres de la portee de l'utilisateur (un AP : uniquement ses tribus).
     * Filtres : recherche, tribu, departement, statut (actif/inactif), profil incomplet, FISS du mois manquante.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('members.view_all') || $user->hasPermission('members.view_scope'), 403, 'Accès refusé.');

        $query = Profile::query()->with([
            'user:id,phone,last_login_at,last_seen_at,activity_status,activity_override',
            'user.roles:id,name',
            'tribe:id,name', 'departments:id,name',
        ]);
        MemberScope::scopeProfiles($query, $user);

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
        $status = $request->query('status');
        if ($status === 'active' || $status === 'inactive') {
            // Statut effectif : force manuellement, sinon statut stocke.
            $query->whereHas('user', fn ($u) => $u->where(fn ($w) => $w->where('activity_override', $status)
                ->orWhere(fn ($x) => $x->whereNull('activity_override')->where('activity_status', $status))));
        }
        if ($request->query('incomplete')) {
            $query->where('completion', '<', 100);
        }
        $period = now()->format('Y-m');
        if ($request->query('fiss_missing')) {
            $query->whereNotIn('user_id', SpiritualHealthForm::where('period', $period)->select('user_id'));
        }

        $profiles = $query->orderBy('last_name')->orderBy('first_name')->limit(1000)->get();
        $filled = SpiritualHealthForm::where('period', $period)->whereIn('user_id', $profiles->pluck('user_id'))->pluck('user_id')->flip();

        // La liste est deja limitee a la portee (celle ou l'utilisateur peut agir), sauf soi-meme.
        $authority = $user->hasPermission('members.view_all');
        $members = $profiles->map(fn (Profile $p) => $this->listItem($p) + [
            'can_manage' => $authority || (int) $p->user_id !== $user->id,
            'fiss_current' => isset($filled[$p->user_id]),
        ]);

        return response()->json(['members' => $members->values(), 'total' => $members->count()]);
    }

    /** Fiche detaillee d'un membre. */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canViewMember($user), 403, 'Accès refusé.');

        $user->load(['profile.tribe', 'profile.gem', 'profile.departments', 'roles.permissions', 'spiritualProfile', 'ledDepartments:id,name']);
        $lastSeen = collect([$user->last_seen_at, $user->last_login_at])->filter()->max();

        return response()->json([
            'user' => array_merge($user->only(['id', 'phone', 'last_login_at']), [
                'activity' => $user->activityStatus(),
                'activity_override' => $user->activity_override,
                'activity_changed_at' => $user->activity_changed_at?->toDateString(),
                'last_seen' => $lastSeen?->toDateString(),
            ]),
            'profile' => $user->profile,
            'spiritual_profile' => $user->spiritualProfile,
            'completion' => $user->profile ? ProfileCompletion::for($user->profile) : null,
            'family' => FamilyService::overview($user),
            'can_manage' => $viewer->canManageMember($user),
            'led_departments' => $user->ledDepartments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
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
        abort_unless($request->user()->canManageMember($user), 403, 'Action non autorisée sur ce membre.');
        $data = $request->validate(['status' => ['required', 'in:active,inactive,auto']]);

        $old = $user->activity_override;
        $user->forceFill(['activity_override' => $data['status'] === 'auto' ? null : $data['status']])->save();
        Audit::log('member.status_forced', $user, $user->id, ['activity_override' => $old], ['activity_override' => $user->activity_override]);

        return response()->json([
            'message' => 'Statut mis à jour.',
            'activity' => $user->activityStatus(),
            'activity_override' => $user->activity_override,
        ]);
    }

    /**
     * Appartenance (tribu, GEM, departements). Le changement de TRIBU est reserve a l'autorite
     * pastorale (members.view_all) ; les autres responsables passent par une demande de changement.
     */
    public function updateBelonging(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->canManageMember($user), 403, 'Action non autorisée sur ce membre.');

        $data = $request->validate([
            'tribe_id' => ['nullable', 'exists:tribes,id'],
            'gem_id' => ['nullable', 'exists:gems,id'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);

        $profile = $user->profile;
        abort_unless($profile, 404, 'Profil introuvable.');

        $tribeId = array_key_exists('tribe_id', $data) ? ($data['tribe_id'] ? (int) $data['tribe_id'] : null) : $profile->tribe_id;
        if ((int) $tribeId !== (int) $profile->tribe_id && $profile->tribe_id && ! $actor->hasPermission('members.view_all')) {
            throw ValidationException::withMessages([
                'tribe_id' => 'Le changement de tribu passe par une demande du membre, validée par les responsables des deux tribus.',
            ]);
        }
        GemRules::assertGemInTribe($data['gem_id'] ?? null, $tribeId);

        $before = ['tribe_id' => $profile->tribe_id, 'gem_id' => $profile->gem_id, 'department_ids' => $profile->departments()->pluck('departments.id')->sort()->values()->all()];
        $profile->update(['tribe_id' => $tribeId, 'gem_id' => $data['gem_id'] ?? null]);
        if (array_key_exists('department_ids', $data)) {
            $profile->departments()->sync($data['department_ids'] ?? []);
        }
        GemRules::afterTribeChange($profile->fresh(), $actor->id);
        $after = ['tribe_id' => $profile->tribe_id, 'gem_id' => $profile->fresh()->gem_id, 'department_ids' => $profile->departments()->pluck('departments.id')->sort()->values()->all()];
        [$old, $new] = Audit::diff($before, $after);
        if ($new) {
            Audit::log(isset($new['tribe_id']) ? 'tribe.changed' : 'member.belonging_updated', $profile, $user->id, $old, $new, ['by' => 'authority']);
        }
        ProfileCompletion::refresh($profile->fresh());

        return response()->json([
            'message' => 'Appartenance mise à jour.',
            'profile' => $profile->fresh()->load('tribe', 'gem', 'departments'),
        ]);
    }

    private function scopeName(?string $kind, ?int $id): ?string
    {
        if (! $kind || ! $id) {
            return null;
        }

        return match ($kind) {
            'tribe' => Tribe::find($id)?->name,
            'gem' => Gem::find($id)?->name,
            'member' => Profile::where('user_id', $id)->first()?->full_name,
            default => Department::find($id)?->name,
        };
    }

    private function listItem(Profile $p): array
    {
        $u = $p->user;
        $lastSeen = collect([$u?->last_seen_at, $u?->last_login_at])->filter()->max();

        return [
            'user_id' => $p->user_id,
            'full_name' => $p->full_name ?: '(profil incomplet)',
            'phone' => $u?->phone,
            'photo_url' => $p->photo_url,
            'tribe' => $p->tribe?->name,
            'departments' => $p->departments->pluck('name')->values(),
            'is_completed' => (bool) $p->is_completed,
            'completion' => (int) $p->completion,
            'activity' => $u ? $u->activityStatus() : 'inactive',
            'last_seen' => $lastSeen?->toDateString(),
            'roles' => $u ? $u->roles->pluck('name')->values() : [],
        ];
    }
}
