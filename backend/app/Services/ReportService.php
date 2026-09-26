<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Rapports et statistiques (tribu, « mes tribus » ou toute l'eglise).
 *
 * Definitions (documentees dans le README) :
 * - Membres : profils completes rattaches a la portee.
 * - Actif / inactif : statut stocke (3 mois sans connexion, FISS ni presence => inactif).
 * - Taux FISS du mois : fiches remplies / membres inscrits a la fin du mois.
 * - Score de vie spirituelle : moyenne des scores FISS (0-100) des fiches REMPLIES ; les
 *   membres sans fiche ne comptent pas comme 0 (ils apparaissent dans « donnees manquantes »).
 * - Vertumetre : moyenne des notes /20 donnees par les responsables.
 * - Assiduite : presences au culte / (sessions pointees x membres actifs), plafonne a 100 %.
 * Toutes les donnees sont limitees a la portee du demandeur (controle serveur).
 */
class ReportService
{
    /** Portees de rapport accessibles : toute l'eglise (autorite), ses tribus, et « mes tribus ». */
    public static function options(User $user): array
    {
        $all = $user->hasPermission('members.view_all');
        $tribes = Tribe::when(! $all, fn ($q) => $q->whereIn('id', $user->scopeTribeIds() ?: [0]))
            ->orderBy('name')->get(['id', 'name']);

        return [
            'church' => $all,
            'mine' => ! $all && $tribes->count() > 1,
            'tribes' => $tribes->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
        ];
    }

    /**
     * Traduit « church » / « mine » / « tribe:ID » en liste de tribus, en verifiant les droits.
     *
     * @return array{key: string, label: string, tribe_ids: array<int>|null}
     */
    public static function resolveScope(User $user, string $scope): array
    {
        $options = self::options($user);
        if ($scope === 'church') {
            abort_unless($options['church'], 403, "Vous n'avez pas accès à la vue de toute l'église.");

            return ['key' => 'church', 'label' => "Toute l'église", 'tribe_ids' => null];
        }
        $allowed = array_column($options['tribes'], 'id');
        if ($scope === 'mine') {
            abort_unless($allowed, 403, "Aucune tribu ne vous est assignée.");

            return ['key' => 'mine', 'label' => 'Mes tribus', 'tribe_ids' => $allowed];
        }
        if (preg_match('/^tribe:(\d+)$/', $scope, $m) && in_array((int) $m[1], $allowed, true)) {
            $name = collect($options['tribes'])->firstWhere('id', (int) $m[1])['name'];

            return ['key' => $scope, 'label' => 'Tribu '.$name, 'tribe_ids' => [(int) $m[1]]];
        }
        abort(403, "Cette tribu ne fait pas partie de votre périmètre.");
    }

    /** Membres (profils completes) de la portee. */
    public static function members(?array $tribeIds): Collection
    {
        return Profile::where('is_completed', true)
            ->when($tribeIds !== null, fn ($q) => $q->whereIn('tribe_id', $tribeIds ?: [0]))
            ->with(['user:id,phone,last_login_at,last_seen_at,activity_status,activity_override', 'tribe:id,name', 'gem:id,name'])
            ->get();
    }

    /** Rapport complet (mis en cache 10 minutes). */
    public static function build(User $user, string $scope, int $months): array
    {
        $resolved = self::resolveScope($user, $scope);
        $months = max(1, min(12, $months));
        $slot = now()->format('YmdH').intdiv((int) now()->format('i'), 10);

        return Cache::remember("report:{$resolved['key']}:{$months}:{$slot}", 600, fn () => self::compute($resolved, $months));
    }

    private static function compute(array $scope, int $months): array
    {
        $members = self::members($scope['tribe_ids']);
        $ids = $members->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        // Statut de chaque membre calcule une fois.
        $statusOf = $members->mapWithKeys(fn (Profile $p) => [$p->user_id => $p->user ? $p->user->activityStatus() : 'inactive'])->all();
        $status = fn (Profile $p) => $statusOf[$p->user_id] ?? 'inactive';
        $active = $members->filter(fn ($p) => $status($p) === 'active');

        $end = now()->endOfMonth();
        $start = now()->startOfMonth()->subMonths($months - 1);
        $periods = [];
        for ($m = $start->copy(); $m->lte($end); $m->addMonth()) {
            $periods[] = $m->format('Y-m');
        }

        // Lignes brutes (sans construire un objet par fiche : plusieurs milliers sur 12 mois).
        $forms = SpiritualHealthForm::whereIn('user_id', $ids ?: [0])->whereIn('period', $periods)->toBase()
            ->get(['user_id', 'period', 'meditation', 'priere', 'jeune', 'sanctification_corps', 'sanctification_ame',
                'sanctification_esprit', 'situation_financiere', 'situation_familiale', 'situation_conjugale']);
        $evaluations = Evaluation::whereIn('user_id', $ids ?: [0])
            ->whereBetween('evaluated_on', [$start->toDateString(), $end->toDateString()])->toBase()->get(['user_id', 'score', 'evaluated_on']);
        $attendance = Attendance::whereIn('member_user_id', $ids ?: [0])->where('kind', 'culte')
            ->whereBetween('attended_on', [$start->toDateString(), $end->toDateString()])
            ->toBase()->get(['member_user_id', 'attended_on', 'event', 'status']);
        $participations = EventParticipation::whereIn('user_id', $ids ?: [0])->where('response', 'present')
            ->whereBetween('occurs_on', [$start->toDateString(), $end->toDateString()])->toBase()->get(['occurs_on']);
        $eventsQuery = Event::where('is_personal', false)->when($scope['tribe_ids'] !== null, fn ($q) => $q->where(fn ($w) => $w
            ->whereHas('scopes', fn ($s) => $s->where('scope_type', 'church'))
            ->orWhereHas('scopes', fn ($s) => $s->where('scope_type', 'tribe')->whereIn('scope_id', $scope['tribe_ids'] ?: [0]))));
        $occurrences = CalendarService::occurrences($eventsQuery, $start->copy(), $end->copy());

        // Une seule passe sur les donnees (et non une par mois) : le calcul reste rapide
        // meme pour 500 membres sur 12 mois.
        $joined = array_count_values($members->map(fn ($p) => $p->created_at ? $p->created_at->format('Y-m') : '9999-99')->all());
        ksort($joined);
        // Scores calcules une seule fois par fiche, ranges par mois puis par membre.
        $formsByPeriod = [];
        foreach ($forms as $f) {
            $formsByPeriod[$f->period][(int) $f->user_id] = [
                'spiritual' => SpiritualHealthForm::spiritualScoreOf($f),
                'social' => SpiritualHealthForm::socialScoreOf($f),
            ];
        }
        $month = fn ($date) => substr((string) ($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date), 0, 10);
        $evalByMonth = [];
        foreach ($evaluations as $e) {
            $evalByMonth[substr($month($e->evaluated_on), 0, 7)][] = (float) $e->score;
        }
        $attByMonth = [];
        foreach ($attendance as $a) {
            $day = $month($a->attended_on);
            $key = substr($day, 0, 7);
            $attByMonth[$key]['sessions'][$day.'|'.$a->event] = true;
            $attByMonth[$key]['present'] = ($attByMonth[$key]['present'] ?? 0) + ($a->status === 'present' ? 1 : 0);
        }
        $partByMonth = array_count_values($participations->map(fn ($p) => substr($month($p->occurs_on), 0, 7))->all());
        $eventsByMonth = array_count_values($occurrences->map(fn ($o) => $o['start']->format('Y-m'))->all());
        $activeCount = $active->count();

        $monthly = [];
        foreach ($periods as $period) {
            $mStart = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
            $eligible = array_sum(array_filter($joined, fn ($n, $m) => $m <= $period, ARRAY_FILTER_USE_BOTH));
            $mForms = $formsByPeriod[$period] ?? [];
            $spiritual = collect(array_column($mForms, 'spiritual'))->filter(fn ($v) => $v !== null);
            $social = collect(array_column($mForms, 'social'))->filter(fn ($v) => $v !== null);
            $mEval = $evalByMonth[$period] ?? [];
            $sessions = count($attByMonth[$period]['sessions'] ?? []);
            $present = $attByMonth[$period]['present'] ?? 0;
            $denominator = $sessions * max(1, $activeCount);

            $monthly[] = [
                'month' => $period,
                'label' => ucfirst($mStart->locale('fr')->isoFormat('MMM YY')),
                'members' => $eligible,
                'new_members' => $joined[$period] ?? 0,
                'fiss_filled' => count($mForms),
                'fiss_rate' => $eligible ? round(count($mForms) / $eligible * 100, 1) : null,
                'spiritual_score' => $spiritual->count() ? round($spiritual->avg(), 1) : null,
                'social_score' => $social->count() ? round($social->avg(), 1) : null,
                'vertumetre' => $mEval ? round(array_sum($mEval) / count($mEval), 1) : null,
                'attendance_sessions' => $sessions,
                'attendance_present' => $present,
                'attendance_rate' => $sessions ? min(100, round($present / $denominator * 100, 1)) : null,
                'events' => $eventsByMonth[$period] ?? 0,
                'participations' => $partByMonth[$period] ?? 0,
            ];
        }

        $current = end($monthly);
        $currentPeriod = now()->format('Y-m');
        $filledNow = array_keys($formsByPeriod[$currentPeriod] ?? []);
        $row = fn (Profile $p) => ['user_id' => $p->user_id, 'name' => $p->full_name, 'tribe' => $p->tribe?->name];

        $report = [
            'scope' => ['key' => $scope['key'], 'label' => $scope['label']],
            'generated_at' => now()->toIso8601String(),
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'months' => $months],
            'kpis' => [
                'members' => $members->count(),
                'active' => $active->count(),
                'inactive' => $members->count() - $active->count(),
                'new_members' => array_sum(array_column($monthly, 'new_members')),
                'profile_completion_avg' => $members->count() ? (int) round($members->avg('completion')) : null,
                'incomplete_profiles' => $members->where('completion', '<', 100)->count(),
                'fiss_rate' => $current['fiss_rate'],
                'fiss_missing' => $active->reject(fn ($p) => in_array((int) $p->user_id, $filledNow, true))->count(),
                'spiritual_score' => self::latest($monthly, 'spiritual_score'),
                'social_score' => self::latest($monthly, 'social_score'),
                'vertumetre' => $evaluations->count() ? round((float) $evaluations->avg('score'), 1) : null,
                'attendance_rate' => self::average($monthly, 'attendance_rate'),
                'events' => array_sum(array_column($monthly, 'events')),
                'participations' => array_sum(array_column($monthly, 'participations')),
            ],
            'monthly' => $monthly,
            'missing_fiss' => $active->reject(fn ($p) => in_array((int) $p->user_id, $filledNow, true))->take(100)->map($row)->values()->all(),
            'inactive_members' => $members->filter(fn ($p) => $status($p) === 'inactive')->take(100)
                ->map(fn ($p) => $row($p) + ['since' => $p->user?->activity_changed_at?->toDateString()])->values()->all(),
            'new_members_list' => $members->filter(fn ($p) => $p->created_at && $p->created_at->gte($start))
                ->sortByDesc('created_at')->take(50)->map(fn ($p) => $row($p) + ['date' => $p->created_at->toDateString()])->values()->all(),
            'tribes' => [],
        ];

        // Comparaison par tribu (vue eglise ou « mes tribus »).
        if ($scope['tribe_ids'] === null || count($scope['tribe_ids']) > 1) {
            $tribes = Tribe::when($scope['tribe_ids'] !== null, fn ($q) => $q->whereIn('id', $scope['tribe_ids']))->orderBy('name')->get(['id', 'name']);
            foreach ($tribes as $tribe) {
                $tm = $members->where('tribe_id', $tribe->id);
                $tIds = $tm->pluck('user_id')->map(fn ($id) => (int) $id)->all();
                $tribeMembers = array_flip($tIds);
                $tForms = collect(array_intersect_key($formsByPeriod[$currentPeriod] ?? [], $tribeMembers));
                $tPrevForms = collect(array_intersect_key($formsByPeriod[now()->subMonthNoOverflow()->format('Y-m')] ?? [], $tribeMembers));
                $scores = $tForms->pluck('spiritual')->filter(fn ($v) => $v !== null);
                $prevScores = $tPrevForms->pluck('spiritual')->filter(fn ($v) => $v !== null);
                $tActive = $tm->filter(fn ($p) => $status($p) === 'active')->count();
                $report['tribes'][] = [
                    'id' => $tribe->id,
                    'name' => $tribe->name,
                    'members' => $tm->count(),
                    'active' => $tActive,
                    'inactive' => $tm->count() - $tActive,
                    'fiss_rate' => $tm->count() ? round($tForms->count() / $tm->count() * 100, 1) : null,
                    'spiritual_score' => $scores->count() ? round($scores->avg(), 1) : null,
                    'spiritual_score_previous' => $prevScores->count() ? round($prevScores->avg(), 1) : null,
                    'fiss_count' => $scores->count(),
                    'completion_avg' => $tm->count() ? (int) round($tm->avg('completion')) : null,
                ];
            }
        }

        return $report;
    }

    /**
     * Liste des membres d'une portee, filtree, cherchee et triee par la base.
     * Les statistiques (derniere FISS, presences, Vertumetre) ne sont calculees que pour la
     * page affichee (ou pour tous si $page est null : export PDF).
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function memberRows(User $user, string $scope, string $filter, string $search = '', ?int $page = 1, int $perPage = 60): array
    {
        $resolved = self::resolveScope($user, $scope);
        $period = now()->format('Y-m');

        $query = Profile::where('is_completed', true)
            ->when($resolved['tribe_ids'] !== null, fn ($q) => $q->whereIn('tribe_id', $resolved['tribe_ids'] ?: [0]));
        match ($filter) {
            'active', 'inactive' => $query->whereHas('user', fn ($u) => $u->where(fn ($w) => $w->where('activity_override', $filter)
                ->orWhere(fn ($x) => $x->whereNull('activity_override')->where('activity_status', $filter)))),
            'incomplete' => $query->where('completion', '<', 100),
            'fiss_missing' => $query->whereNotIn('user_id', SpiritualHealthForm::where('period', $period)->select('user_id')),
            default => null,
        };
        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($w) use ($search) {
                \App\Support\Like::contains($w, 'first_name', $search);
                \App\Support\Like::contains($w, 'last_name', $search, 'or');
                $w->orWhereHas('user', fn ($u) => \App\Support\Like::contains($u, 'phone', $search));
            });
        }
        $total = (clone $query)->count();
        $members = $query->with(['user:id,phone,last_login_at,last_seen_at,activity_status,activity_override', 'tribe:id,name', 'gem:id,name'])
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->when($page !== null, fn ($q) => $q->forPage($page, $perPage))
            ->get();
        $ids = $members->pluck('user_id')->all();

        // Seulement la derniere fiche de chaque membre affiche (et non tout l'historique).
        $latest = SpiritualHealthForm::selectRaw('user_id as uid, max(period) as last_period')
            ->whereIn('user_id', $ids ?: [0])->groupBy('user_id');
        $lastForms = SpiritualHealthForm::query()->select('spiritual_health_forms.*')
            ->joinSub($latest, 'latest', fn ($j) => $j->on('spiritual_health_forms.user_id', '=', 'latest.uid')
                ->on('spiritual_health_forms.period', '=', 'latest.last_period'))
            ->get()->keyBy('user_id');
        $presences = Attendance::whereIn('member_user_id', $ids ?: [0])->where('status', 'present')
            ->where('attended_on', '>=', now()->subMonths(3)->toDateString())
            ->selectRaw('member_user_id, count(*) as c')->groupBy('member_user_id')->pluck('c', 'member_user_id');
        $vertumetre = Evaluation::whereIn('user_id', $ids ?: [0])->selectRaw('user_id, avg(score) as a')->groupBy('user_id')->pluck('a', 'user_id');

        $rows = $members->map(function (Profile $p) use ($lastForms, $presences, $vertumetre, $period) {
            $u = $p->user;
            $form = $lastForms->get($p->user_id);
            $seen = collect([$u?->last_seen_at, $u?->last_login_at])->filter()->max();

            return [
                'user_id' => $p->user_id,
                'name' => $p->full_name,
                'phone' => $u?->phone,
                'tribe' => $p->tribe?->name,
                'gem' => $p->gem?->name,
                'status' => $u ? $u->activityStatus() : 'inactive',
                'last_seen' => $seen?->toIso8601String(),
                'completion' => (int) $p->completion,
                'last_fiss' => $form?->period,
                'fiss_current' => $form?->period === $period,
                'fiss_score' => $form?->spiritualScore(),
                'attendance_3m' => (int) ($presences[$p->user_id] ?? 0),
                'vertumetre' => isset($vertumetre[$p->user_id]) ? round((float) $vertumetre[$p->user_id], 1) : null,
            ];
        })->values()->all();

        return ['rows' => $rows, 'total' => $total];
    }

    private static function latest(array $monthly, string $key): ?float
    {
        foreach (array_reverse($monthly) as $m) {
            if ($m[$key] !== null) {
                return $m[$key];
            }
        }

        return null;
    }

    private static function average(array $monthly, string $key): ?float
    {
        $values = array_filter(array_column($monthly, $key), fn ($v) => $v !== null);

        return $values ? round(array_sum($values) / count($values), 1) : null;
    }
}
