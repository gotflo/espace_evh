<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Activite des membres. Regle : un membre est INACTIF lorsqu'aucune activite n'a ete
 * enregistree depuis 3 mois — ni connexion / utilisation de l'application, ni fiche FISS
 * remplie, ni presence pointee. Le compte n'est jamais supprime ; il redevient actif des
 * qu'il reprend une activite. Chaque changement de statut est trace dans le journal d'audit.
 * Un statut force manuellement (activity_override) l'emporte toujours a l'affichage.
 */
class ActivityService
{
    /** Signe d'activite d'un membre (requete authentifiee, FISS, presence) : met a jour et reactive. */
    public static function touch(User $user, bool $seen = true, string $reason = 'usage'): void
    {
        $updates = [];
        if ($seen && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(10)))) {
            $updates['last_seen_at'] = now();
        }
        if ($user->activity_status === 'inactive') {
            $updates['activity_status'] = 'active';
            $updates['activity_changed_at'] = now();
            Audit::log('member.reactivated', $user, $user->id, ['activity_status' => 'inactive'], ['activity_status' => 'active'], ['reason' => $reason]);
        }
        if ($updates) {
            $user->forceFill($updates)->saveQuietly();
        }
    }

    /**
     * Recalcul de tous les statuts (idempotent, lance chaque jour). Retourne les changements.
     *
     * @return array{inactivated: array<int>, reactivated: array<int>}
     */
    public static function refreshAll(): array
    {
        $lastAttendance = Attendance::select('member_user_id', DB::raw('MAX(attended_on) as d'))
            ->groupBy('member_user_id')->pluck('d', 'member_user_id');
        $lastFiss = SpiritualHealthForm::select('user_id', DB::raw('MAX(COALESCE(submitted_at, created_at)) as d'))
            ->groupBy('user_id')->pluck('d', 'user_id');

        $changes = ['inactivated' => [], 'reactivated' => []];
        User::whereHas('profile', fn ($p) => $p->where('is_completed', true))
            ->select(['id', 'last_login_at', 'last_seen_at', 'activity_status', 'created_at'])
            ->chunkById(300, function ($users) use ($lastAttendance, $lastFiss, &$changes) {
                foreach ($users as $user) {
                    $dates = array_filter([
                        $user->last_seen_at,
                        $user->last_login_at,
                        isset($lastAttendance[$user->id]) ? Carbon::parse($lastAttendance[$user->id]) : null,
                        isset($lastFiss[$user->id]) ? Carbon::parse($lastFiss[$user->id]) : null,
                        $user->created_at, // un nouvel inscrit n'est pas inactif des le depart
                    ]);
                    $last = $dates ? collect($dates)->max() : null;
                    $status = User::activityFrom(null, $last);
                    if ($status === $user->activity_status) {
                        continue;
                    }
                    $previous = $user->activity_status;
                    $user->forceFill(['activity_status' => $status, 'activity_changed_at' => now()])->saveQuietly();
                    Audit::log($status === 'inactive' ? 'member.inactivated' : 'member.reactivated', $user, $user->id,
                        ['activity_status' => $previous], ['activity_status' => $status],
                        ['last_activity' => $last?->toDateString(), 'rule' => '3 mois sans connexion, FISS ni présence'], null);
                    $changes[$status === 'inactive' ? 'inactivated' : 'reactivated'][] = $user->id;
                }
            });

        return $changes;
    }
}
