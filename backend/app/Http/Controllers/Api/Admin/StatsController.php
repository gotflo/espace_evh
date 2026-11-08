<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Gem;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Support\MemberScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    /** Chiffres cles pour le tableau de bord, limites a la portee de l'utilisateur. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $viewAll = $user->hasPermission('members.view_all');

        if (! $viewAll && ! $user->hasPermission('members.view_scope')) {
            abort(403, 'Accès refusé.');
        }

        $scope = fn ($query) => MemberScope::scopeProfiles($query, $user);

        $total = $scope(Profile::query())->count();
        $completed = $scope(Profile::query())->where('is_completed', true)->count();

        // Actifs : statut force manuellement, sinon statut stocke (mis a jour chaque jour).
        $active = $scope(Profile::query())->whereHas('user', fn ($u) => $u->where(fn ($w) => $w->where('activity_override', 'active')
            ->orWhere(fn ($x) => $x->whereNull('activity_override')->where('activity_status', 'active'))))->count();
        $incomplete = $scope(Profile::query())->where('is_completed', true)->where('completion', '<', 100)->count();
        // FISS du mois : seulement sur les membres dont l'utilisateur voit les fiches (ses tribus, ou
        // tous) ; un Garde ou un responsable de departement n'a pas ce chiffre.
        $fissTribes = $user->fissTribeIds();
        $fissScope = fn ($query) => $scope($query)->where('is_completed', true)
            ->when($fissTribes !== null, fn ($q) => $q->whereIn('tribe_id', $fissTribes ?: [0]));
        $fissMembers = $fissTribes === [] ? null : $fissScope(Profile::query())->count();
        $fissFilled = $fissTribes === [] ? null
            : $fissScope(Profile::query())->whereIn('user_id', SpiritualHealthForm::where('period', now()->format('Y-m'))->select('user_id'))->count();

        // Repartition par tribu (limitee aux tribus visibles).
        $byTribe = $scope(Profile::query())
            ->selectRaw('tribe_id, count(*) as total')
            ->whereNotNull('tribe_id')
            ->groupBy('tribe_id')
            ->with('tribe:id,name')
            ->get()
            ->map(fn ($row) => ['name' => $row->tribe?->name ?? '-', 'total' => (int) $row->total])
            ->sortByDesc('total')->values();

        // Nouveaux inscrits (30 derniers jours) : d'abord ceux qui restent a accueillir.
        $recent = NewMemberController::recentQuery($user)
            ->reorder()->orderByRaw('CASE WHEN welcomed_at IS NULL THEN 0 ELSE 1 END')->orderByRaw(NewMemberController::ARRIVAL.' desc')
            ->limit(6)->get()
            ->map(fn (Profile $p) => NewMemberController::present($p))->values();

        // Plusieurs groupes suivis (tribu, GEM, departements) : les chiffres de chacun, jamais seulement le total melange.
        $groups = [];
        if (! $viewAll) {
            $ids = $user->scopesFor('members.view_scope');
            $isActive = fn ($u) => $u->where(fn ($w) => $w->where('activity_override', 'active')
                ->orWhere(fn ($x) => $x->whereNull('activity_override')->where('activity_status', 'active')));
            $count = function (callable $filter) use ($scope, $isActive) {
                return [
                    'total' => $filter($scope(Profile::query()))->count(),
                    'active' => $filter($scope(Profile::query()))->whereHas('user', $isActive)->count(),
                ];
            };
            foreach (Tribe::whereIn('id', $ids['tribe'] ?: [0])->orderBy('name')->get(['id', 'name']) as $t) {
                $groups[] = ['type' => 'tribe', 'id' => $t->id, 'name' => "Tribu {$t->name}"] + $count(fn ($q) => $q->where('tribe_id', $t->id));
            }
            foreach (Gem::whereIn('id', $ids['gem'] ?: [0])->orderBy('name')->get(['id', 'name']) as $g) {
                $groups[] = ['type' => 'gem', 'id' => $g->id, 'name' => $g->label()] + $count(fn ($q) => $q->where('gem_id', $g->id));
            }
            foreach (Department::whereIn('id', $ids['department'] ?: [0])->orderBy('name')->get(['id', 'name']) as $d) {
                $groups[] = ['type' => 'department', 'id' => $d->id, 'name' => $d->name]
                    + $count(fn ($q) => $q->whereHas('departments', fn ($x) => $x->whereKey($d->id)));
            }
            if (count($groups) < 2) {
                $groups = [];
            }
        }

        return response()->json([
            'groups' => $groups,
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'completed' => $completed,
            'incomplete_profiles' => $incomplete,
            'fiss_filled' => $fissFilled,
            'fiss_rate' => $fissMembers ? round($fissFilled / $fissMembers * 100) : null,
            'by_tribe' => $byTribe,
            'recent' => $recent,
            'new_members' => NewMemberController::counts($user),
        ]);
    }
}
