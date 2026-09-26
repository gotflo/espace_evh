<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\Milestone;
use App\Models\SpiritualHealthForm;
use App\Services\FissService;
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

        // Assiduite detaillee : sessions pointees pour ce fidele sur 3 mois.
        $recent = Attendance::where('member_user_id', $user->id)->where('attended_on', '>=', $since)
            ->orderByDesc('attended_on')->limit(30)->get(['attended_on', 'event', 'kind', 'status']);
        $sessions = $recent->where('kind', 'culte')->count();

        // FISS du mois et evolution.
        $forms = SpiritualHealthForm::where('user_id', $user->id)->orderByDesc('period')->limit(6)->get();
        $current = $forms->firstWhere('period', now()->format('Y-m'));

        return response()->json([
            'assiduite' => $cultePresences,
            'attendance' => [
                'present' => $cultePresences,
                'sessions' => $sessions,
                'rate' => $sessions ? round($cultePresences / $sessions * 100) : null,
                'recent' => $recent->map(fn ($a) => [
                    'date' => substr((string) $a->attended_on, 0, 10),
                    'event' => $a->event,
                    'kind' => $a->kind,
                    'status' => $a->status,
                ])->values(),
            ],
            'fiss' => [
                'filled' => (bool) $current,
                'period_label' => FissService::periodLabel(now()->format('Y-m')),
                'score' => $current?->spiritualScore(),
                'trend' => $forms->reverse()->values()->map(fn ($f) => [
                    'period' => $f->period,
                    'label' => ucfirst(\Illuminate\Support\Carbon::createFromFormat('Y-m-d', $f->period.'-01')->locale('fr')->isoFormat('MMM')),
                    'score' => $f->spiritualScore(),
                ]),
            ],
            'note_moyenne' => $evalAvg !== null ? round((float) $evalAvg, 1) : null,
            'parcours' => $milestones,
            'rehearsal' => $rehearsal,
        ]);
    }
}
