<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaderReport;
use App\Services\LeaderReportService;
use App\Support\Audit;
use App\Support\LeaderReportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rapports mensuels des responsables (patriarche : tribu ; responsables : departement).
 * Droits verifies ici : remplir = etre patriarche de la tribu ou responsable du departement ;
 * lire = auteurs, AP de la tribu, autorites pastorales (voir LeaderReportService).
 */
class LeaderReportController extends Controller
{
    /** Mois proposes dans la liste des rapports recus. */
    private const HISTORY_MONTHS = 12;

    /** Mes rapports a remplir et, pour ceux qui les recoivent, l'etat des rapports d'un mois. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $due = LeaderReportService::duePeriod();
        $open = LeaderReportService::openPeriods();
        $period = $this->period($request->query('period')) ?? $due;

        $mine = LeaderReportService::fillableScopes($user);
        $review = LeaderReportService::reviewScopes($user);
        abort_unless($mine || LeaderReportService::canReview($user), 403, 'Cette page est réservée aux responsables concernés.');

        $reports = LeaderReport::whereIn('period', array_unique([...$open, $period]))->with('author.profile:id,user_id,first_name,last_name')->get()
            ->keyBy(fn (LeaderReport $r) => $r->kind.':'.$r->scope_id.':'.$r->period);

        return response()->json([
            'due_period' => $due,
            'due_label' => LeaderReportService::periodLabel($due),
            'mine' => array_map(fn ($s) => $s + [
                'periods' => array_map(function (string $p) use ($s, $reports) {
                    $r = $reports->get($s['kind'].':'.$s['scope_id'].':'.$p);

                    return ['period' => $p, 'label' => LeaderReportService::periodLabel($p), 'status' => $r?->status ?? 'missing',
                        'report_id' => $r?->id, 'submitted_at' => $r?->submitted_at?->toIso8601String()];
                }, $open),
            ], $mine),
            'can_review' => LeaderReportService::canReview($user),
            'period' => $period,
            'period_label' => LeaderReportService::periodLabel($period),
            'periods' => $this->history(),
            'received' => array_map(function ($s) use ($reports, $period) {
                $r = $reports->get($s['kind'].':'.$s['scope_id'].':'.$period);
                $sent = $r?->isSubmitted();

                return $s + [
                    'status' => $sent ? 'submitted' : 'missing',
                    'report_id' => $sent ? $r->id : null,
                    'submitted_at' => $sent ? $r->submitted_at?->toIso8601String() : null,
                    'author' => $sent ? $r->author?->profile?->full_name : null,
                    'souls_count' => $sent ? count($r->souls ?? []) + count($r->answers['ames_autres'] ?? []) : null,
                ];
            }, $review),
        ]);
    }

    /** Questionnaire d'un rapport a remplir : questions, reponses deja saisies, reperes du mois. */
    public function form(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(LeaderReport::KINDS)],
            'scope_id' => ['required', 'integer'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $user = $request->user();
        $scope = collect(LeaderReportService::fillableScopes($user))
            ->first(fn ($s) => $s['kind'] === $data['kind'] && $s['scope_id'] === (int) $data['scope_id']);
        abort_unless($scope, 403, "Ce rapport n'est pas à votre charge.");

        $report = LeaderReport::where(['kind' => $scope['kind'], 'scope_id' => $scope['scope_id'], 'period' => $data['period']])->first();

        return response()->json($scope + [
            'period' => $data['period'],
            'period_label' => LeaderReportService::periodLabel($data['period']),
            'open' => LeaderReportService::isOpen($data['period']),
            'closes_on' => LeaderReportService::closesOn($data['period'])->toDateString(),
            'status' => $report?->status ?? 'missing',
            'report_id' => $report?->id,
            'answers' => (object) ($report?->answers ?? []),
            'steps' => LeaderReportCatalog::steps($scope['kind']),
            'context' => LeaderReportService::context($scope['kind'], $scope['scope_id'], $data['period']),
        ]);
    }

    /** Enregistre le brouillon (submit absent) ou envoie le rapport (submit = true). */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(LeaderReport::KINDS)],
            'scope_id' => ['required', 'integer'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'answers' => ['present', 'array'],
            'submit' => ['sometimes', 'boolean'],
        ]);
        $submit = (bool) ($data['submit'] ?? false);
        $report = LeaderReportService::save($request->user(), $data['kind'], (int) $data['scope_id'], $data['period'], $data['answers'], $submit);

        return response()->json([
            'message' => $submit ? 'Rapport envoyé. Merci !' : 'Brouillon enregistré.',
            'report_id' => $report->id,
            'status' => $report->status,
        ]);
    }

    /** Lecture d'un rapport envoye (ou de son propre brouillon). */
    public function show(Request $request, LeaderReport $report): JsonResponse
    {
        $user = $request->user();
        abort_unless(LeaderReportService::canRead($user, $report), 403, 'Ce rapport ne fait pas partie de votre périmètre.');
        $report->load('author.profile:id,user_id,first_name,last_name');

        return response()->json([
            'id' => $report->id,
            'kind' => $report->kind,
            'scope_id' => $report->scope_id,
            'scope_name' => $report->scope_name,
            'period' => $report->period,
            'period_label' => LeaderReportService::periodLabel($report->period),
            'status' => $report->status,
            'author' => $report->author?->profile?->full_name,
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
            'can_edit' => LeaderReportService::canFill($user, $report->kind, $report->scope_id) && LeaderReportService::isOpen($report->period),
            'answers' => (object) ($report->answers ?? []),
            'steps' => LeaderReportCatalog::steps($report->kind),
            // Rapport envoye : ames figees a l'envoi ; brouillon : liste du moment.
            'souls' => $report->isSubmitted() ? ($report->souls ?? []) : LeaderReportService::souls($report->kind, $report->scope_id, $report->period),
        ]);
    }

    /** Trace l'export PDF d'un rapport (genere sur l'appareil a partir des donnees autorisees). */
    public function logExport(Request $request, LeaderReport $report): JsonResponse
    {
        abort_unless(LeaderReportService::canRead($request->user(), $report), 403, 'Ce rapport ne fait pas partie de votre périmètre.');
        Audit::log('leader_report.exported', $report, null, [], [], ['kind' => $report->kind, 'scope' => $report->scope_name, 'period' => $report->period]);

        return response()->json(['message' => 'ok']);
    }

    private function period(mixed $value): ?string
    {
        return is_string($value) && in_array($value, array_column($this->history(), 'period'), true) ? $value : null;
    }

    /** @return array<int, array{period: string, label: string}> du mois en cours aux 12 precedents */
    private function history(): array
    {
        $months = [];
        for ($i = 0; $i <= self::HISTORY_MONTHS; $i++) {
            $p = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $months[] = ['period' => $p, 'label' => LeaderReportService::periodLabel($p)];
        }

        return $months;
    }
}
