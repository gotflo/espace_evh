<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
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
        $fissFilled = $scope(Profile::query())->whereIn('user_id', \App\Models\SpiritualHealthForm::where('period', now()->format('Y-m'))->select('user_id'))->count();

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
            ->reorder()->orderByRaw('CASE WHEN welcomed_at IS NULL THEN 0 ELSE 1 END')->orderByDesc('profiles.created_at')
            ->limit(6)->get()
            ->map(fn (Profile $p) => NewMemberController::present($p))->values();

        return response()->json([
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'completed' => $completed,
            'incomplete_profiles' => $incomplete,
            'fiss_filled' => $fissFilled,
            'fiss_rate' => $completed ? round($fissFilled / $completed * 100) : null,
            'by_tribe' => $byTribe,
            'recent' => $recent,
            'new_members' => NewMemberController::counts($user),
        ]);
    }
}
