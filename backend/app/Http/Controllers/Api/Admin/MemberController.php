<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Services\DepartmentJoinService;
use App\Services\BelongingNotices;
use App\Services\FamilyService;
use App\Services\MemberDeletionService;
use App\Support\Audit;
use App\Support\DepartmentLeadership;
use App\Support\GemRules;
use App\Support\Like;
use App\Support\MemberScope;
use App\Support\ProfileCompletion;
use App\Support\RoleGrants;
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

        if ($q = mb_substr(trim((string) $request->query('q')), 0, 80)) {
            $query->where(function ($sub) use ($q) {
                Like::contains($sub, 'first_name', $q);
                Like::contains($sub, 'last_name', $q, 'or');
                $sub->orWhereHas('user', fn ($u) => Like::contains($u, 'phone', $q));
            });
        }
        if ($tribe = $request->query('tribe_id')) {
            $query->where('tribe_id', $tribe);
        }
        if ($dept = $request->query('department_id')) {
            $query->whereHas('departments', fn ($d) => $d->where('departments.id', $dept));
        }
        if ($gem = $request->query('gem_id')) {
            $query->where('gem_id', $gem);
        }
        $status = $request->query('status');
        if ($status === 'active' || $status === 'inactive') {
            // Statut effectif : force manuellement, sinon statut stocke.
            $query->whereHas('user', fn ($u) => $u->where(fn ($w) => $w->where('activity_override', $status)
                ->orWhere(fn ($x) => $x->whereNull('activity_override')->where('activity_status', $status))));
        }
        // Absents : aucune connexion depuis plus d'une semaine.
        if ($request->query('absent')) {
            $query->whereHas('user', fn ($u) => $u->whereRaw('COALESCE(last_seen_at, last_login_at) < ?', [now()->subDays(7)->toDateTimeString()]));
        }
        if ($request->query('incomplete')) {
            $query->where('completion', '<', 100);
        }
        $period = now()->format('Y-m');
        // FISS : seulement pour les membres dont l'utilisateur voit les fiches (ses tribus, ou tous).
        $fissTribes = $user->fissTribeIds();
        if ($request->query('fiss_missing')) {
            $query->when($fissTribes !== null, fn ($q) => $q->whereIn('tribe_id', $fissTribes ?: [0]))
                ->whereNotIn('user_id', SpiritualHealthForm::where('period', $period)->select('user_id'));
        }

        // Pagination : 50 membres par page (jamais des milliers de lignes vers un telephone).
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();
        $profiles = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->forPage($page, $perPage)->get();
        $filled = SpiritualHealthForm::where('period', $period)->whereIn('user_id', $profiles->pluck('user_id'))->pluck('user_id')->flip();

        // La liste est deja limitee a la portee (celle ou l'utilisateur peut agir), sauf soi-meme.
        $authority = $user->hasPermission('members.view_all');
        $members = $profiles->map(fn (Profile $p) => $this->listItem($p) + [
            'can_manage' => $authority || (int) $p->user_id !== $user->id,
            'fiss_current' => $fissTribes === null || in_array((int) $p->tribe_id, $fissTribes, true) ? isset($filled[$p->user_id]) : null,
        ]);

        return response()->json([
            'members' => $members->values(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < $total,
        ]);
    }

    /** Fiche detaillee d'un membre. */
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canViewMember($user), 403, 'Accès refusé.');
        // Inscription inachevee : la personne n'apparait pas encore chez les responsables.
        abort_unless((bool) $user->profile?->is_completed, 404, "Ce membre n'a pas encore terminé son inscription.");

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
            'spiritual_profile' => $viewer->canViewMember($user, 'spiritual.view') ? $user->spiritualProfile : null,
            'completion' => $user->profile ? ProfileCompletion::for($user->profile) : null,
            'family' => $this->familyFor($viewer, $user),
            'can_manage' => $viewer->canManageMember($user),
            // Ce que ce responsable peut faire sur CE membre (portees comprises) : l'ecran n'affiche que cela,
            // jamais un bouton ou un panneau que le serveur refuserait ensuite.
            'abilities' => [
                'evaluations_view' => $viewer->canViewMember($user, 'evaluations.manage'),
                'evaluations_manage' => $viewer->canManageMember($user, 'evaluations.manage'),
                'spiritual_record' => $viewer->canManageMember($user, 'spiritual.record'),
                'exercises_view' => $viewer->canViewMember($user, 'exercises.view_responses'),
                'deletion' => MemberDeletionService::canDelete($viewer, $user) || MemberDeletionService::canRequest($viewer, $user)
                    || $viewer->canManageMember($user),
                'assign_roles' => $viewer->hasPermission('roles.assign') && ! $user->isPlatformManager()
                    && ($viewer->isSuperAdmin() || $viewer->id !== $user->id),
            ],
            // Appartenance modifiable : « full » (tribu, GEM, departements), « gem » (le GEM seulement) ou null.
            'belonging_edit' => self::belongingRights($viewer, $user),
            'can_view_fiss' => $viewer->canViewFissOf($user),
            'can_view_meditations' => $viewer->canViewMeditationsOf($user),
            // Deja affiche par le role « Responsable de departement » sur ce departement : pas de doublon.
            'led_departments' => $user->ledDepartments->reject(fn ($d) => $user->roles->contains(fn ($r) => DepartmentLeadership::isLeaderRole($r, $r->pivot->scope_kind)
                && (int) $r->pivot->scope_id === (int) $d->id))->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'roles' => $user->roles->map(fn ($r) => [
                'assignment_id' => $r->pivot->id,
                'key' => $r->key,
                'name' => $r->name,
                'scope_kind' => $r->pivot->scope_kind,
                'scope_id' => $r->pivot->scope_id,
                'scope_name' => $this->scopeName($r->pivot->scope_kind, $r->pivot->scope_id),
                // Memes regles que le retrait (RoleController::revoke) : rang, soi-meme, role de Protocole lie.
                'can_remove' => $viewer->hasPermission('roles.assign') && ! $user->isPlatformManager()
                    && RoleGrants::assignRefusal($viewer, $r, $user, $r->pivot->scope_kind, $r->pivot->scope_id ? (int) $r->pivot->scope_id : null) === null
                    && ! DepartmentLeadership::isProtocolLeadership($r, $r->pivot->scope_kind)
                    && ! ($r->key === User::SUPER_ADMIN && $user->id === $viewer->id),
            ])->values(),
        ]);
    }

    /**
     * Forcer manuellement le statut d'un membre, ou revenir au calcul automatique.
     * status = active | inactive | auto
     */
    public function setActivity(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->canManageMember($user, 'members.edit'), 403, 'Action non autorisée sur ce membre.');
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
     * Appartenance (tribu, GEM, departements) : chaque partie a sa propre regle, sans melange (voir belongingRights).
     * - tribu : autorite pastorale ; les autres passent par la demande de changement du membre ;
     * - GEM : celui qui compose les GEMs de la tribu du membre (patriarche ou AP de cette tribu, pasteurs) ;
     * - departements : members.edit, et hors autorite pastorale seulement les departements dont on est responsable.
     */
    public function updateBelonging(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $rights = self::belongingRights($actor, $user);
        abort_unless($rights['status'] || $rights['tribe'] || $rights['departments'] || $rights['gem'], 403, 'Action non autorisée sur ce membre.');

        $data = $request->validate([
            'tribe_id' => ['nullable', 'exists:tribes,id'],
            'gem_id' => ['nullable', 'exists:gems,id'],
            'department_ids' => ['nullable', 'array', 'max:60'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);
        // Autorite pastorale pour modifier l'appartenance (et non un droit recu sur une portee, ex. Administrateur +
        // Accompagnateur d'un fidele).
        $pastoral = $actor->isChurchWideFor('members.edit');

        $profile = $user->profile;
        abort_unless($profile, 404, 'Profil introuvable.');

        // --- Tribu
        $tribeId = array_key_exists('tribe_id', $data) ? ($data['tribe_id'] ? (int) $data['tribe_id'] : null) : $profile->tribe_id;
        $sameTribe = (int) $tribeId === (int) $profile->tribe_id;
        if (! $sameTribe && ! $rights['tribe']) {
            throw ValidationException::withMessages(['tribe_id' => $profile->tribe_id
                ? 'Le changement de tribu passe par une demande du membre, validée par les responsables des deux tribus.'
                : 'La tribu de ce membre se choisit par un pasteur.']);
        }

        // --- GEM (sans valeur : inchange, sauf changement de tribu qui fait quitter le GEM)
        $gemId = array_key_exists('gem_id', $data)
            ? ($data['gem_id'] ? (int) $data['gem_id'] : null)
            : ($sameTribe ? $profile->gem_id : null);
        if ($sameTribe && (int) $gemId !== (int) $profile->gem_id && ! $rights['gem']) {
            throw ValidationException::withMessages(['gem_id' => 'Le GEM se change par le patriarche ou l’AP de la tribu.']);
        }
        GemRules::assertGemInTribe($gemId, $tribeId);

        // --- Departements
        $currentDepts = $profile->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $wantedDepts = array_key_exists('department_ids', $data)
            ? collect($data['department_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all()
            : $currentDepts;
        $changed = array_values(array_merge(array_diff($wantedDepts, $currentDepts), array_diff($currentDepts, $wantedDepts)));
        if ($changed && ! $rights['departments']) {
            throw ValidationException::withMessages(['department_ids' => 'Les départements de ce membre se changent par leurs responsables.']);
        }
        // Hors autorite pastorale : seulement les departements de sa portee ; s'inscrire a un autre service
        // passe par la demande du membre, validee par son responsable.
        if ($changed && ! $pastoral && array_diff($changed, $actor->scopesFor('members.edit')['department'])) {
            throw ValidationException::withMessages([
                'department_ids' => 'Vous ne pouvez changer que les départements dont vous êtes responsable : l\'inscription à un autre service passe par la demande du membre.',
            ]);
        }

        $before = ['tribe_id' => $profile->tribe_id, 'gem_id' => $profile->gem_id, 'department_ids' => $currentDepts];
        $profile->update(['tribe_id' => $tribeId, 'gem_id' => $gemId]);
        if ($changed) {
            $profile->departments()->sync($wantedDepts);
            foreach (Department::whereIn('id', array_diff($currentDepts, $wantedDepts))->get() as $left) {
                DepartmentLeadership::leave($left, $user->id, $actor->id);
            }
            // Ajoute directement : sa demande d'inscription en attente n'a plus d'objet.
            foreach (Department::whereIn('id', array_diff($wantedDepts, $currentDepts))->get() as $joined) {
                DepartmentJoinService::cancel($user, $joined);
            }
        }
        GemRules::afterTribeChange($profile->fresh(), $actor->id);
        if (! $sameTribe && $before['gem_id'] && (int) $before['gem_id'] !== (int) $gemId) {
            GemRules::tellGardeOfTribeDeparture((int) $before['gem_id'], $profile->fresh(), $actor->id);
        }
        $after = ['tribe_id' => $profile->tribe_id, 'gem_id' => $profile->fresh()->gem_id, 'department_ids' => $profile->departments()->pluck('departments.id')->sort()->values()->all()];
        [$old, $new] = Audit::diff($before, $after);
        if ($new) {
            Audit::log(isset($new['tribe_id']) ? 'tribe.changed' : 'member.belonging_updated', $profile, $user->id, $old, $new, ['by' => 'authority']);
            // Le membre, la tribu qui l'accueille et le Garde de son nouveau GEM en sont prevenus.
            $ints = fn (array $ids) => array_map('intval', $ids);
            BelongingNotices::after($user->load('profile'),
                ['tribe_id' => $before['tribe_id'], 'gem_id' => $before['gem_id'], 'department_ids' => $ints($before['department_ids'])],
                ['tribe_id' => $after['tribe_id'], 'gem_id' => $after['gem_id'], 'department_ids' => $ints($after['department_ids'])], $actor);
        }
        ProfileCompletion::refresh($profile->fresh());

        return response()->json([
            'message' => 'Appartenance mise à jour.',
            'profile' => $profile->fresh()->load('tribe', 'gem', 'departments'),
        ]);
    }

    /**
     * Famille vue par un responsable : un lien vers la fiche d'un proche seulement si elle s'ouvrira pour lui
     * (fiche terminee, dans sa portee). Les demandes recues et suggestions de conjoint restent au membre lui-meme.
     *
     * @return array<string, mixed>
     */
    private function familyFor(User $viewer, User $member): array
    {
        $family = FamilyService::overview($member);
        $opens = function (?int $id) use ($viewer): bool {
            $relative = $id ? User::with('profile')->find($id) : null;

            return (bool) ($relative?->profile?->is_completed && $viewer->canViewMember($relative));
        };
        if ($family['spouse']) {
            $family['spouse']['can_open'] = $opens($family['spouse']['user_id'] ?? null);
        }
        $family['children'] = array_map(fn (array $c) => $c + ['can_open' => $opens($c['user_id'] ?? null)], $family['children']);
        unset($family['incoming'], $family['suggestions']);

        return $family;
    }

    /**
     * Ce que l'utilisateur peut changer chez ce membre, chaque droit venant de sa propre source :
     * - status, departments : members.edit sur ce membre (portee comprise : un responsable de departement, ses membres) ;
     * - tribe : autorite pastorale (ou membre encore sans tribu, avec members.edit) ;
     * - gem : composition des GEMs de la tribu du membre (patriarche, AP de cette tribu, pasteurs), jamais par un
     *   role de departement.
     *
     * @return array{status: bool, tribe: bool, departments: bool, department_ids: array<int>|null, gem: bool}
     */
    public static function belongingRights(User $actor, User $member): array
    {
        $full = $actor->canManageMember($member, 'members.edit');
        $pastoral = $full && $actor->isChurchWideFor('members.edit');
        $tribeId = $member->profile?->tribe_id ? (int) $member->profile->tribe_id : null;

        return [
            'status' => $full,
            'tribe' => $full && ($pastoral || $tribeId === null),
            'departments' => $full,
            // Departements qu'il peut cocher ou decocher (null : tous, autorite pastorale).
            'department_ids' => $full && ! $actor->isChurchWideFor('members.edit') ? $actor->scopesFor('members.edit')['department'] : null,
            'gem' => $pastoral || ($actor->canManageMember($member) && GemRules::canPlaceMembers($actor, $tribeId)),
        ];
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
            // Plus d'une semaine sans connexion : nombre de jours (affiche dans la liste).
            'absent_days' => $lastSeen && $lastSeen->lt(now()->subDays(7)) ? (int) $lastSeen->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null,
            'roles' => $u ? $u->roles->pluck('name')->values() : [],
        ];
    }
}
