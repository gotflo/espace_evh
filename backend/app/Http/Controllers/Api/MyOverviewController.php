<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\Milestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyOverviewController extends Controller
{
    /** Statistiques gamifiees du fidele (assiduite, vertumetre, parcours, ponctualite). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = now()->subMonths(3);

        $cultePresences = Attendance::where('member_user_id', $user->id)
            ->where('kind', 'culte')->where('status', 'present')
            ->where('attended_on', '>=', $since)->count();

        $evalAvg = Evaluation::where('user_id', $user->id)->avg('score');
        $milestones = Milestone::where('member_user_id', $user->id)->count();

        // Ponctualite aux repetitions : uniquement si le fidele est dans un departement a repetition.
        $rehearsal = null;
        $profile = $user->profile;
        if ($profile && $profile->departments()->where('departments.tracks_rehearsal', true)->exists()) {
            $base = Attendance::where('member_user_id', $user->id)->where('kind', 'repetition')->where('attended_on', '>=', $since);
            $total = (clone $base)->count();
            $present = (clone $base)->where('status', 'present')->count();
            $retard = (clone $base)->where('status', 'retard')->count();
            $absentJust = (clone $base)->where('status', 'absent_justifie')->count();
            $absent = (clone $base)->where('status', 'absent')->count();
            $rehearsal = [
                'total' => $total,
                'present' => $present,
                'retard' => $retard,
                'absent_justifie' => $absentJust,
                'absent' => $absent,
                'punctuality_rate' => $total ? round(($present) / $total * 100) : null,
            ];
        }

        return response()->json([
            'assiduite' => $cultePresences,
            'note_moyenne' => $evalAvg !== null ? round((float) $evalAvg, 1) : null,
            'parcours' => $milestones,
            'rehearsal' => $rehearsal,
        ]);
    }
}
