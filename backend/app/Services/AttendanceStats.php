<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Assiduite d'un membre au culte. Seules les presences sont enregistrees a l'appel : le nombre de
 * sessions vient donc des appels reellement faits pour son groupe (GEM, a defaut tribu), et non de
 * ses propres lignes (qui donnaient toujours 100 %, voire plus).
 */
class AttendanceStats
{
    /**
     * Presences / sessions (date + culte) pointees pour le GEM du membre (a defaut sa tribu) depuis
     * $since (ou depuis son arrivee), plafonne a 100 %. Sans groupe ni session pointee : pas de taux
     * (null) plutot qu'un chiffre trompeur.
     *
     * @return array{present: int, sessions: int, rate: int|null}
     */
    public static function culte(User $user, Carbon $since): array
    {
        $from = ($user->created_at && $user->created_at->gt($since) ? $user->created_at : $since)->toDateString();
        $present = Attendance::where('member_user_id', $user->id)->where('kind', 'culte')
            ->where('status', 'present')->where('attended_on', '>=', $from)->count();

        $profile = $user->profile;
        $peers = match (true) {
            (bool) $profile?->gem_id => Profile::where('gem_id', $profile->gem_id)->select('user_id'),
            (bool) $profile?->tribe_id => Profile::where('tribe_id', $profile->tribe_id)->select('user_id'),
            default => null,
        };
        if ($peers === null) {
            return ['present' => $present, 'sessions' => 0, 'rate' => null];
        }
        $sessions = Attendance::where('kind', 'culte')->where('attended_on', '>=', $from)
            ->where(fn ($q) => $q->whereIn('member_user_id', $peers)->orWhere('member_user_id', $user->id))
            ->toBase()->select(['attended_on', 'event'])->distinct()->get()->count();

        return [
            'present' => $present,
            'sessions' => $sessions,
            'rate' => $sessions ? (int) min(100, round($present / $sessions * 100)) : null,
        ];
    }
}
