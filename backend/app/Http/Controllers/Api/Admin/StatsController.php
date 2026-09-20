<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Support\MemberScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatsController extends Controller
{
    /** Chiffres cles pour le tableau de bord, limites a la portee de l'utilisateur. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $viewAll = $user->hasPermission('members.view_all');

        if (! $viewAll && ! $user->hasPermission('members.view_scope')) {
            abort(403, 'Acces refuse.');
        }

        $scope = fn ($query) => MemberScope::scopeProfiles($query, $user);

        $total = $scope(Profile::query())->count();
        $completed = $scope(Profile::query())->where('is_completed', true)->count();

        // Actifs = statut d'activite calcule (presence/connexion recente) ou force actif.
        $active = $scope(Profile::query())
            ->with(['user' => fn ($q) => $q->withMax('attendances as last_attendance_date', 'attended_on')])
            ->get()
            ->filter(function (Profile $p) {
                $u = $p->user;
                return $u && User::activityFrom($u->activity_override, $this->lastSeen($u)) === 'active';
            })->count();

        // Repartition par tribu (limitee aux tribus visibles).
        $byTribe = $scope(Profile::query())
            ->selectRaw('tribe_id, count(*) as total')
            ->whereNotNull('tribe_id')
            ->groupBy('tribe_id')
            ->with('tribe:id,name')
            ->get()
            ->map(fn ($row) => ['name' => $row->tribe?->name ?? '-', 'total' => (int) $row->total])
            ->sortByDesc('total')->values();

        // Derniers inscrits.
        $recent = $scope(Profile::query())
            ->with('user:id,phone')
            ->latest()->take(5)->get()
            ->map(fn (Profile $p) => [
                'user_id' => $p->user_id,
                'full_name' => $p->full_name ?: '(profil incomplet)',
                'photo_url' => $p->photo_url,
            ]);

        return response()->json([
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'completed' => $completed,
            'by_tribe' => $byTribe,
            'recent' => $recent,
        ]);
    }

    private function lastSeen(User $u): ?Carbon
    {
        $dates = array_filter([
            $u->last_attendance_date ? Carbon::parse($u->last_attendance_date) : null,
            $u->last_login_at,
        ]);

        return empty($dates) ? null : collect($dates)->max();
    }
}
