<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gem;
use App\Models\GemWeeklyReport;
use App\Models\Profile;
use App\Services\GemReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rapport hebdomadaire du Garde pour son GEM (voir GemReportService). */
class GemReportController extends Controller
{
    /** GEMs du Garde : membres, rapport de la semaine attendue (ou cases precochees), semaines precedentes. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $gems = GemReportService::ledGems($user);
        abort_if($gems->isEmpty(), 403, 'Cette page est réservée aux Gardes.');

        $due = GemReportService::dueWeek();
        $reports = GemWeeklyReport::whereIn('gem_id', $gems->pluck('id'))
            ->where('week_start', '>=', $due->copy()->subWeeks(GemReportService::HISTORY_WEEKS)->toDateString())
            ->orderByDesc('week_start')->get()->groupBy('gem_id');

        return response()->json([
            'due_week' => $due->toDateString(),
            'due_label' => GemReportService::weekLabel($due),
            'closes_on' => $due->copy()->addDays(12)->toDateString(),
            'gems' => $gems->map(function (Gem $gem) use ($reports, $due) {
                $mine = $reports->get($gem->id, collect());
                $current = $mine->first(fn (GemWeeklyReport $r) => substr((string) $r->week_start, 0, 10) === $due->toDateString());

                return [
                    'gem_id' => $gem->id,
                    'name' => $gem->name,
                    'tribe' => $gem->tribe?->name,
                    'members' => GemReportService::members($gem)->map(fn (Profile $p) => [
                        'user_id' => (int) $p->user_id, 'name' => $p->full_name, 'photo_url' => $p->photo_url,
                    ])->values(),
                    'current' => $current ? GemReportService::present($current) : null,
                    'culte_prefill' => $current ? [] : GemReportService::cultePrefill($gem, $due),
                    'history' => $mine->reject(fn (GemWeeklyReport $r) => $r->is($current))
                        ->map(fn (GemWeeklyReport $r) => GemReportService::present($r))->values(),
                ];
            })->values(),
        ]);
    }

    /** Envoie ou corrige le rapport de la semaine attendue. */
    public function save(Request $request, Gem $gem): JsonResponse
    {
        $data = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
            'meeting_held' => ['required', 'boolean'],
            'attendance' => ['present', 'array', 'max:60'],
            'attendance.*.user_id' => ['required', 'integer'],
            'attendance.*.culte' => ['sometimes', 'boolean'],
            'attendance.*.rencontre' => ['sometimes', 'boolean'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $report = GemReportService::save($request->user(), $gem, $data['week_start'], (bool) $data['meeting_held'], $data['attendance'], $data['comment'] ?? null);

        return response()->json([
            'message' => 'Rapport de la semaine envoyé. Merci !',
            'report' => GemReportService::present($report),
        ]);
    }
}
