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
use App\Support\Like;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        $all = $user->isChurchWideFor('reports.view');
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
            abort_unless($allowed, 403, 'Aucune tribu ne vous est assignée.');

            return ['key' => 'mine', 'label' => 'Mes tribus', 'tribe_ids' => $allowed];
        }
        if (preg_match('/^tribe:(\d+)$/', $scope, $m) && in_array((int) $m[1], $allowed, true)) {
            $name = collect($options['tribes'])->firstWhere('id', (int) $m[1])['name'];

            return ['key' => $scope, 'label' => 'Tribu '.$name, 'tribe_ids' => [(int) $m[1]]];
        }
        abort(403, 'Cette tribu ne fait pas partie de votre périmètre.');
    }

    /** Statut effectif (voir User::activityStatus) calcule par la base : 1 si actif, un membre sans compte n'est pas actif. */
    private const ACTIVE_SQL = "case when u.id is null then 0 when u.activity_override = 'active' then 1 when u.activity_override = 'inactive' then 0 when u.activity_status is null or u.activity_status = '' or u.activity_status = 'active' then 1 else 0 end";

    /** Membres (profils completes) de la portee. */
    private static function members(?array $tribeIds): Builder
    {
        return Profile::query()->where('profiles.is_completed', true)
            ->when($tribeIds !== null, fn ($q) => $q->whereIn('profiles.tribe_id', $tribeIds ?: [0]));
    }

    /** Lignes d'un membre pour les listes du rapport : ordre stable, colonnes utiles seulement. */
    private static function listed(?array $tribeIds, string $statusSql): Builder
    {
        return self::members($tribeIds)
            ->leftJoin('users as u', 'u.id', '=', 'profiles.user_id')
            ->whereRaw($statusSql)
            ->with('tribe:id,name')
            ->when($tribeIds !== null, fn ($q) => $q->orderBy('profiles.tribe_id'))
            ->orderBy('profiles.id')
            ->select(['profiles.id', 'profiles.user_id', 'profiles.first_name', 'profiles.last_name', 'profiles.tribe_id', 'profiles.created_at', 'u.activity_changed_at as activity_changed_at']);
    }

    /** Rapport complet (mis en cache 10 minutes). */
    public static function build(User $user, string $scope, int $months): array
    {
        $resolved = self::resolveScope($user, $scope);
        $months = max(1, min(12, $months));
        $slot = now()->format('YmdH').intdiv((int) now()->format('i'), 10);

        // « Mes tribus » depend du demandeur : les tribus resolues font partie de la cle, sinon un AP
        // recevrait le rapport (en cache) des tribus d'un autre AP.
        $tribeKey = '';
        if ($resolved['tribe_ids'] !== null && $resolved['key'] === 'mine') {
            $ids = array_map('intval', $resolved['tribe_ids']);
            sort($ids);
            $tribeKey = ':'.md5(implode(',', $ids));
        }
        $key = "report:{$resolved['key']}{$tribeKey}:{$months}:{$slot}";
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // Un seul calcul a la fois pour un meme rapport : si plusieurs responsables l'ouvrent au
        // meme instant, les autres attendent son resultat au lieu de tout recalculer en parallele
        // (mesure : 10 demandes simultanees = 10 calculs, 18,5 s sur un coeur).
        try {
            return Cache::lock($key.':calcul', 60)->block(30, fn () => Cache::remember($key, 600, fn () => self::compute($resolved, $months)));
        } catch (LockTimeoutException) {
            return Cache::remember($key, 600, fn () => self::compute($resolved, $months));
        }
    }

    /**
     * Tout est agrege par la base (par mois et par tribu) : aucune fiche, aucun membre n'est garde en memoire.
     * Seul le score d'une fiche reste calcule ici, une ligne a la fois (memes regles que l'ecran de la fiche).
     */
    private static function compute(array $scope, int $months): array
    {
        $tribeIds = $scope['tribe_ids'];
        $memberIds = fn () => self::members($tribeIds)->select('profiles.user_id');

        $end = now()->endOfMonth();
        $start = now()->startOfMonth()->subMonths($months - 1);
        $periods = [];
        for ($m = $start->copy(); $m->lte($end); $m->addMonth()) {
            $periods[] = $m->format('Y-m');
        }

        // Membres : effectifs, actifs et completion, par tribu ; arrivees par mois.
        $byTribe = self::members($tribeIds)->leftJoin('users as u', 'u.id', '=', 'profiles.user_id')->toBase()
            ->selectRaw('profiles.tribe_id as tribe_id, count(*) as members, sum('.self::ACTIVE_SQL.') as active, sum(profiles.completion) as completion_sum, count(profiles.completion) as completion_count, sum(case when profiles.completion is null or profiles.completion < 100 then 1 else 0 end) as incomplete')
            ->groupBy('profiles.tribe_id')->get();
        $memberCount = (int) $byTribe->sum('members');
        $activeCount = (int) $byTribe->sum('active');
        $completionCount = (int) $byTribe->sum('completion_count');
        $completionSum = (int) $byTribe->sum('completion_sum');
        $incomplete = (int) $byTribe->sum('incomplete');
        $byTribe = $byTribe->filter(fn ($t) => $t->tribe_id !== null)->keyBy('tribe_id');

        $joined = [];
        foreach (self::members($tribeIds)->toBase()->selectRaw('substr(profiles.created_at, 1, 7) as month, count(*) as total')->groupBy('month')->get() as $row) {
            $joined[$row->month ?? '9999-99'] = (int) $row->total;
        }
        ksort($joined);

        // Fiches : une seule passe, un score par fiche, additionne par mois ; par tribu pour le mois et le mois precedent.
        $currentPeriod = now()->format('Y-m');
        $previousPeriod = now()->subMonthNoOverflow()->format('Y-m');
        $formsByPeriod = [];
        $tribeForms = [];
        $forms = DB::table('spiritual_health_forms as f')->join('profiles', 'profiles.user_id', '=', 'f.user_id')
            ->where('profiles.is_completed', true)
            ->when($tribeIds !== null, fn ($q) => $q->whereIn('profiles.tribe_id', $tribeIds ?: [0]))
            ->whereIn('f.period', $periods)
            ->select(['f.period', 'profiles.tribe_id', 'f.meditation', 'f.priere', 'f.jeune', 'f.sanctification_corps', 'f.sanctification_ame',
                'f.sanctification_esprit', 'f.situation_financiere', 'f.situation_familiale', 'f.situation_conjugale']);
        foreach ($forms->cursor() as $f) {
            $spiritual = SpiritualHealthForm::spiritualScoreOf($f);
            $social = SpiritualHealthForm::socialScoreOf($f);
            $month = &$formsByPeriod[$f->period];
            $month ??= ['count' => 0, 'spiritual_sum' => 0.0, 'spiritual_count' => 0, 'social_sum' => 0.0, 'social_count' => 0];
            $month['count']++;
            if ($spiritual !== null) {
                $month['spiritual_sum'] += $spiritual;
                $month['spiritual_count']++;
            }
            if ($social !== null) {
                $month['social_sum'] += $social;
                $month['social_count']++;
            }
            unset($month);
            if ($f->tribe_id !== null && ($f->period === $currentPeriod || $f->period === $previousPeriod)) {
                $tribe = &$tribeForms[$f->tribe_id][$f->period];
                $tribe ??= ['count' => 0, 'spiritual_sum' => 0.0, 'spiritual_count' => 0];
                $tribe['count']++;
                if ($spiritual !== null) {
                    $tribe['spiritual_sum'] += $spiritual;
                    $tribe['spiritual_count']++;
                }
                unset($tribe);
            }
        }

        $evalByMonth = [];
        foreach (Evaluation::whereIn('user_id', $memberIds())->whereBetween('evaluated_on', [$start->toDateString(), $end->toDateString()])->toBase()
            ->selectRaw('substr(evaluated_on, 1, 7) as month, sum(score) as total, count(*) as notes')->groupBy('month')->get() as $row) {
            $evalByMonth[$row->month] = ['sum' => (float) $row->total, 'count' => (int) $row->notes];
        }
        // Une ligne par session (jour + evenement) avec son nombre de presents.
        $attByMonth = [];
        foreach (Attendance::whereIn('member_user_id', $memberIds())->where('kind', 'culte')->whereBetween('attended_on', [$start->toDateString(), $end->toDateString()])->toBase()
            ->selectRaw("attended_on, event, sum(case when status = 'present' then 1 else 0 end) as present")->groupBy('attended_on', 'event')->get() as $row) {
            $key = substr((string) $row->attended_on, 0, 7);
            $attByMonth[$key]['sessions'] = ($attByMonth[$key]['sessions'] ?? 0) + 1;
            $attByMonth[$key]['present'] = ($attByMonth[$key]['present'] ?? 0) + (int) $row->present;
        }
        $partByMonth = [];
        foreach (EventParticipation::whereIn('user_id', $memberIds())->where('response', 'present')->whereBetween('occurs_on', [$start->toDateString(), $end->toDateString()])->toBase()
            ->selectRaw('substr(occurs_on, 1, 7) as month, count(*) as total')->groupBy('month')->get() as $row) {
            $partByMonth[$row->month] = (int) $row->total;
        }
        $eventsQuery = Event::where('is_personal', false)->when($tribeIds !== null, fn ($q) => $q->where(fn ($w) => $w
            ->whereHas('scopes', fn ($s) => $s->where('scope_type', 'church'))
            ->orWhereHas('scopes', fn ($s) => $s->where('scope_type', 'tribe')->whereIn('scope_id', $tribeIds ?: [0]))));
        $occurrences = CalendarService::occurrences($eventsQuery, $start->copy(), $end->copy());
        $eventsByMonth = array_count_values($occurrences->map(fn ($o) => $o['start']->format('Y-m'))->all());

        $monthly = [];
        foreach ($periods as $period) {
            $mStart = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
            $eligible = array_sum(array_filter($joined, fn ($n, $m) => $m <= $period, ARRAY_FILTER_USE_BOTH));
            $mForms = $formsByPeriod[$period] ?? ['count' => 0, 'spiritual_sum' => 0.0, 'spiritual_count' => 0, 'social_sum' => 0.0, 'social_count' => 0];
            $mEval = $evalByMonth[$period] ?? null;
            $sessions = $attByMonth[$period]['sessions'] ?? 0;
            $present = $attByMonth[$period]['present'] ?? 0;
            $denominator = $sessions * max(1, $activeCount);

            $monthly[] = [
                'month' => $period,
                'label' => ucfirst($mStart->locale('fr')->isoFormat('MMM YY')),
                'members' => $eligible,
                'new_members' => $joined[$period] ?? 0,
                'fiss_filled' => $mForms['count'],
                'fiss_rate' => $eligible ? round($mForms['count'] / $eligible * 100, 1) : null,
                'spiritual_score' => $mForms['spiritual_count'] ? round($mForms['spiritual_sum'] / $mForms['spiritual_count'], 1) : null,
                'social_score' => $mForms['social_count'] ? round($mForms['social_sum'] / $mForms['social_count'], 1) : null,
                'vertumetre' => $mEval ? round($mEval['sum'] / $mEval['count'], 1) : null,
                'attendance_sessions' => $sessions,
                'attendance_present' => $present,
                'attendance_rate' => $sessions ? min(100, round($present / $denominator * 100, 1)) : null,
                'events' => $eventsByMonth[$period] ?? 0,
                'participations' => $partByMonth[$period] ?? 0,
            ];
        }

        $current = end($monthly);
        $row = fn (Profile $p) => ['user_id' => $p->user_id, 'name' => $p->full_name, 'tribe' => $p->tribe?->name];
        // Membres actifs sans fiche ce mois-ci : la base les compte et ne renvoie que ceux de la liste.
        $missingFiss = fn () => self::listed($tribeIds, self::ACTIVE_SQL.' = 1')
            ->whereNotIn('profiles.user_id', SpiritualHealthForm::where('period', $currentPeriod)->select('user_id'));
        $evalNotes = array_sum(array_column($evalByMonth, 'count'));

        $report = [
            'scope' => ['key' => $scope['key'], 'label' => $scope['label']],
            'generated_at' => now()->toIso8601String(),
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'months' => $months],
            'kpis' => [
                'members' => $memberCount,
                'active' => $activeCount,
                'inactive' => $memberCount - $activeCount,
                'new_members' => array_sum(array_column($monthly, 'new_members')),
                'profile_completion_avg' => $memberCount ? ($completionCount ? (int) round($completionSum / $completionCount) : 0) : null,
                'incomplete_profiles' => $incomplete,
                'fiss_rate' => $current['fiss_rate'],
                'fiss_missing' => $missingFiss()->count(),
                'spiritual_score' => self::latest($monthly, 'spiritual_score'),
                'social_score' => self::latest($monthly, 'social_score'),
                'vertumetre' => $evalNotes ? round(array_sum(array_column($evalByMonth, 'sum')) / $evalNotes, 1) : null,
                'attendance_rate' => self::average($monthly, 'attendance_rate'),
                'events' => array_sum(array_column($monthly, 'events')),
                'participations' => array_sum(array_column($monthly, 'participations')),
            ],
            'monthly' => $monthly,
            'missing_fiss' => $missingFiss()->limit(100)->get()->map($row)->values()->all(),
            'inactive_members' => self::listed($tribeIds, self::ACTIVE_SQL.' = 0')->limit(100)->get()
                ->map(fn ($p) => $row($p) + ['since' => $p->activity_changed_at ? Carbon::parse($p->activity_changed_at)->toDateString() : null])->values()->all(),
            'new_members_list' => self::listed($tribeIds, '1 = 1')->reorder()->where('profiles.created_at', '>=', $start)
                ->orderByDesc('profiles.created_at')->orderBy('profiles.id')->limit(50)->get()
                ->map(fn ($p) => $row($p) + ['date' => $p->created_at->toDateString()])->values()->all(),
            'tribes' => [],
        ];

        // Comparaison par tribu (vue eglise ou « mes tribus »).
        if ($tribeIds === null || count($tribeIds) > 1) {
            $tribes = Tribe::when($tribeIds !== null, fn ($q) => $q->whereIn('id', $tribeIds))->orderBy('name')->get(['id', 'name']);
            foreach ($tribes as $tribe) {
                $t = $byTribe->get($tribe->id);
                $count = (int) ($t->members ?? 0);
                $active = (int) ($t->active ?? 0);
                $tForms = $tribeForms[$tribe->id][$currentPeriod] ?? ['count' => 0, 'spiritual_sum' => 0.0, 'spiritual_count' => 0];
                $tPrev = $tribeForms[$tribe->id][$previousPeriod] ?? ['count' => 0, 'spiritual_sum' => 0.0, 'spiritual_count' => 0];
                $report['tribes'][] = [
                    'id' => $tribe->id,
                    'name' => $tribe->name,
                    'members' => $count,
                    'active' => $active,
                    'inactive' => $count - $active,
                    'fiss_rate' => $count ? round($tForms['count'] / $count * 100, 1) : null,
                    'spiritual_score' => $tForms['spiritual_count'] ? round($tForms['spiritual_sum'] / $tForms['spiritual_count'], 1) : null,
                    'spiritual_score_previous' => $tPrev['spiritual_count'] ? round($tPrev['spiritual_sum'] / $tPrev['spiritual_count'], 1) : null,
                    'fiss_count' => $tForms['spiritual_count'],
                    'completion_avg' => $count ? ((int) ($t->completion_count ?? 0) ? (int) round((int) $t->completion_sum / (int) $t->completion_count) : 0) : null,
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
            'active', 'inactive' => $query->whereHas('user', fn ($u) => ActivityService::whereEffective($u, $filter)),
            'incomplete' => $query->where('completion', '<', 100),
            // Meme population que le compteur et la liste du rapport : membres ACTIFS sans fiche du mois.
            'fiss_missing' => $query->whereNotIn('user_id', SpiritualHealthForm::where('period', $period)->select('user_id'))
                ->whereHas('user', fn ($u) => ActivityService::whereEffective($u, 'active')),
            default => null,
        };
        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($w) use ($search) {
                Like::contains($w, 'first_name', $search);
                Like::contains($w, 'last_name', $search, 'or');
                $w->orWhereHas('user', fn ($u) => Like::contains($u, 'phone', $search));
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
