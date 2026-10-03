<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Gem;
use App\Models\LeaderReport;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Support\Audit;
use App\Support\LeaderReportCatalog;
use App\Support\Recipients;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapports mensuels des responsables :
 * - un rapport par tribu et par mois, rempli par le patriarche de la tribu ;
 * - un rapport par departement et par mois, rempli par ses responsables ;
 * - le rapport d'un mois se remplit a partir du 25 de ce mois et jusqu'a la fin du mois suivant ;
 *   il reste modifiable par ses auteurs pendant cette periode, puis il est fige ;
 * - lecture : les auteurs, l'AP de la tribu, les autorites pastorales (permission rapports) ;
 * - les ames gagnees du mois sont listees automatiquement et figees a l'envoi.
 */
class LeaderReportService
{
    /** Jour du mois a partir duquel le rapport du mois en cours peut etre commence. */
    public const OPENS_ON_DAY = 25;

    /** Mois dont le rapport est attendu : le mois ecoule. */
    public static function duePeriod(): string
    {
        return now()->startOfMonth()->subMonth()->format('Y-m');
    }

    /** @return array<int, string> mois dont le rapport peut etre rempli ou corrige aujourd'hui */
    public static function openPeriods(): array
    {
        return array_values(array_filter([
            self::duePeriod(),
            now()->day >= self::OPENS_ON_DAY ? now()->format('Y-m') : null,
        ]));
    }

    public static function isOpen(string $period): bool
    {
        return in_array($period, self::openPeriods(), true);
    }

    /** Dernier jour pour remplir ou corriger le rapport d'un mois : fin du mois suivant. */
    public static function closesOn(string $period): Carbon
    {
        return self::bounds($period)[0]->addMonth()->endOfMonth();
    }

    public static function periodLabel(string $period): string
    {
        return FissService::periodLabel($period);
    }

    /**
     * Tribus (patriarche) et departements (responsable) dont l'utilisateur remplit le rapport.
     *
     * @return array<int, array{kind: string, scope_id: int, scope_name: string}>
     */
    public static function fillableScopes(User $user): array
    {
        $tribeIds = $user->roles->where('key', 'patriarche')->where('pivot.scope_kind', 'tribe')
            ->pluck('pivot.scope_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $scopes = [];
        foreach (Tribe::whereIn('id', $tribeIds ?: [0])->orderBy('name')->get(['id', 'name']) as $t) {
            $scopes[] = ['kind' => 'tribe', 'scope_id' => $t->id, 'scope_name' => $t->name];
        }
        foreach (Department::whereIn('id', $user->ledDepartmentIds() ?: [0])->orderBy('name')->get(['id', 'name']) as $d) {
            $scopes[] = ['kind' => 'department', 'scope_id' => $d->id, 'scope_name' => $d->name];
        }

        return $scopes;
    }

    public static function canFill(User $user, string $kind, int $scopeId): bool
    {
        return collect(self::fillableScopes($user))->contains(fn ($s) => $s['kind'] === $kind && $s['scope_id'] === $scopeId);
    }

    /** Peut-il lire les rapports recus (au moins ceux de son perimetre) ? */
    public static function canReview(User $user): bool
    {
        return $user->hasPermission('reports.view');
    }

    /**
     * Lecture d'un rapport : ses auteurs toujours ; sinon un rapport envoye, par l'autorite
     * pastorale (tous) ou par les responsables de la tribu (AP, patriarche) pour leur tribu.
     */
    public static function canRead(User $user, LeaderReport $report): bool
    {
        if (self::canFill($user, $report->kind, $report->scope_id)) {
            return true;
        }
        if (! $report->isSubmitted() || ! self::canReview($user)) {
            return false;
        }

        return $user->hasPermission('members.view_all')
            || ($report->kind === 'tribe' && in_array($report->scope_id, $user->scopeTribeIds(), true));
    }

    /**
     * Tribus et departements dont l'utilisateur recoit les rapports, avec leurs responsables.
     *
     * @return array<int, array{kind: string, scope_id: int, scope_name: string, leaders: array<int, string>}>
     */
    public static function reviewScopes(User $user): array
    {
        if (! self::canReview($user)) {
            return [];
        }
        $all = $user->hasPermission('members.view_all');
        $scopes = [];

        $tribes = Tribe::when(! $all, fn ($q) => $q->whereIn('id', $user->scopeTribeIds() ?: [0]))->orderBy('name')->get(['id', 'name']);
        $patriarchs = self::patriarchsByTribe($tribes->pluck('id')->all());
        foreach ($tribes as $t) {
            $scopes[] = ['kind' => 'tribe', 'scope_id' => $t->id, 'scope_name' => $t->name, 'leaders' => $patriarchs[$t->id] ?? []];
        }
        if ($all) {
            // Un departement sans responsable designe n'a personne pour envoyer un rapport.
            $departments = Department::where('is_active', true)->whereHas('leaders')->with('leaders:id')->orderBy('name')->get(['id', 'name']);
            foreach ($departments as $d) {
                $scopes[] = ['kind' => 'department', 'scope_id' => $d->id, 'scope_name' => $d->name,
                    'leaders' => $d->leaders->pluck('id')->map(fn ($id) => (int) $id)->all()];
            }
        }

        // Noms des responsables charges en une seule requete (et non une par tribu / departement).
        $ids = array_values(array_unique(array_merge([], ...array_column($scopes, 'leaders'))));
        $names = $ids ? Profile::whereIn('user_id', $ids)->get(['user_id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Profile $p) => [(int) $p->user_id => $p->full_name]) : collect();

        return array_map(fn ($s) => ['leaders' => array_values(array_filter(array_map(fn ($id) => $names[$id] ?? null, $s['leaders'])))] + $s, $scopes);
    }

    /**
     * Ames gagnees et integrees pendant le mois : nouveaux inscrits de la tribu, ou membres ayant
     * rejoint le departement. « integrated » : le nouvel inscrit a ete accueilli par un responsable.
     *
     * @return array<int, array{user_id: int, name: string, date: string|null, integrated: bool|null}>
     */
    public static function souls(string $kind, int $scopeId, string $period): array
    {
        [$from, $to] = self::bounds($period);

        if ($kind === 'tribe') {
            return Profile::where('tribe_id', $scopeId)->where('is_completed', true)
                ->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get()
                ->map(fn (Profile $p) => [
                    'user_id' => (int) $p->user_id, 'name' => $p->full_name,
                    'date' => $p->created_at?->toDateString(), 'integrated' => $p->welcomed_at !== null,
                ])->values()->all();
        }

        return Profile::query()->join('department_profile as dp', 'dp.profile_id', '=', 'profiles.id')
            ->where('dp.department_id', $scopeId)->whereBetween('dp.created_at', [$from, $to])
            ->orderBy('dp.created_at')->get(['profiles.*', 'dp.created_at as joined_on'])
            ->map(fn (Profile $p) => [
                'user_id' => (int) $p->user_id, 'name' => $p->full_name,
                'date' => substr((string) $p->joined_on, 0, 10), 'integrated' => null,
            ])->values()->all();
    }

    /**
     * Reperes affiches pendant le remplissage : ames du mois, GEMs de la tribu, chiffres du mois.
     *
     * @return array<string, mixed>
     */
    public static function context(string $kind, int $scopeId, string $period): array
    {
        $souls = self::souls($kind, $scopeId, $period);
        [, $to] = self::bounds($period);

        if ($kind === 'department') {
            $members = DB::table('department_profile')->where('department_id', $scopeId)->count();

            return ['souls' => $souls, 'gems' => [], 'indicators' => [
                ['label' => 'Membres du département', 'value' => (string) $members],
                ['label' => 'Arrivées du mois', 'value' => (string) count($souls)],
            ]];
        }

        $memberIds = Profile::where('tribe_id', $scopeId)->where('is_completed', true)
            ->where('created_at', '<=', $to)->pluck('user_id');
        $forms = SpiritualHealthForm::whereIn('user_id', $memberIds)->where('period', $period)->get();
        $scores = $forms->map(fn (SpiritualHealthForm $f) => $f->spiritualScore())->filter(fn ($v) => $v !== null);
        $gems = Gem::where('tribe_id', $scopeId)->where('is_active', true)->with('leader.profile:id,user_id,first_name,last_name')
            ->withCount('members')->orderBy('name')->get();

        return [
            'souls' => $souls,
            'gems' => $gems->map(fn (Gem $g) => [
                'id' => $g->id, 'name' => $g->name, 'leader' => $g->leader?->profile?->full_name ?: null, 'members_count' => $g->members_count,
            ])->values()->all(),
            'indicators' => [
                ['label' => 'Membres de la tribu', 'value' => (string) $memberIds->count()],
                ['label' => 'FISS remplies', 'value' => $memberIds->count() ? $forms->count().' sur '.$memberIds->count() : '—'],
                ['label' => 'Vie spirituelle (moyenne des FISS)', 'value' => $scores->count() ? round($scores->avg()).' %' : '—'],
            ],
        ];
    }

    /**
     * Enregistre le brouillon ou envoie le rapport. Un seul rapport par tribu / departement et par
     * mois : les responsables d'un meme departement travaillent sur le meme rapport.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function save(User $author, string $kind, int $scopeId, string $period, array $answers, bool $submit): LeaderReport
    {
        abort_unless(self::canFill($author, $kind, $scopeId), 403, "Ce rapport n'est pas à votre charge.");
        abort_unless(self::isOpen($period), 423, 'Le rapport de '.self::periodLabel($period).' ne peut plus être modifié.');
        $clean = LeaderReportCatalog::clean($kind, $answers, $submit);
        $scopeName = ($kind === 'tribe' ? Tribe::find($scopeId) : Department::find($scopeId))?->name;

        try {
            [$report, $first] = DB::transaction(function () use ($author, $kind, $scopeId, $scopeName, $period, $clean, $submit) {
                $report = LeaderReport::where(['kind' => $kind, 'scope_id' => $scopeId, 'period' => $period])->lockForUpdate()->first()
                    ?? new LeaderReport(['kind' => $kind, 'scope_id' => $scopeId, 'period' => $period, 'status' => 'draft']);
                // Un rapport deja envoye ne redevient jamais un brouillon : une correction se renvoie.
                abort_if($report->isSubmitted() && ! $submit, 409, 'Ce rapport est déjà envoyé. Pour le corriger, renvoyez-le.');

                $first = $submit && ! $report->isSubmitted();
                $before = $report->answers ?? [];
                $report->fill(['answers' => $clean, 'scope_name' => $scopeName, 'author_user_id' => $author->id]);
                if ($submit) {
                    $report->fill([
                        'status' => 'submitted',
                        'souls' => self::souls($kind, $scopeId, $period),
                        'submitted_at' => $report->submitted_at ?? now(),
                    ]);
                }
                $report->save();

                if ($first) {
                    Audit::log('leader_report.submitted', $report, null, [], [], ['kind' => $kind, 'scope' => $scopeName, 'period' => $period], $author);
                } elseif ($submit) {
                    [$old, $new] = Audit::diff($before, $clean);
                    Audit::log('leader_report.updated', $report, null, $old, $new, ['kind' => $kind, 'scope' => $scopeName, 'period' => $period], $author);
                }

                return [$report, $first];
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Ce rapport vient d\'être enregistré depuis un autre appareil. Rouvrez-le pour continuer.');
        }

        if ($first) {
            $what = $kind === 'tribe' ? 'Tribu '.$scopeName : 'Département '.$scopeName;
            $souls = count($report->souls ?? []) + count($clean['ames_autres'] ?? []);
            Notifier::send(array_diff(self::reviewerIds($kind, $scopeId), [$author->id]), 'leader_report',
                'Rapport mensuel reçu · '.$what,
                ucfirst(self::periodLabel($period)).' · envoyé par '.($author->profile?->full_name ?: $author->phone).' · '.$souls.' âme(s) gagnée(s).',
                '/admin/rapports-mensuels?rapport='.$report->id, ['leader_report_id' => $report->id]);
        }

        return $report;
    }

    /** @return array<int> destinataires d'un rapport envoye : AP de la tribu et autorites pastorales */
    public static function reviewerIds(string $kind, int $scopeId): array
    {
        $ids = Recipients::authorities('reports.view');
        if ($kind === 'tribe') {
            $ids = array_merge($ids, Recipients::tribeRoleHolders($scopeId, ['assistant_pasteur']));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Responsables qui doivent encore envoyer le rapport d'un mois, par tribu / departement.
     *
     * @return array<int, array{kind: string, scope_id: int, scope_name: string, user_ids: array<int>}>
     */
    public static function pending(string $period): array
    {
        $done = LeaderReport::where('period', $period)->where('status', 'submitted')->get(['kind', 'scope_id'])
            ->map(fn (LeaderReport $r) => $r->kind.':'.$r->scope_id)->flip();
        $pending = [];

        $tribes = Tribe::orderBy('name')->get(['id', 'name']);
        $patriarchs = self::patriarchsByTribe($tribes->pluck('id')->all());
        foreach ($tribes as $t) {
            if (! isset($done['tribe:'.$t->id]) && ! empty($patriarchs[$t->id])) {
                $pending[] = ['kind' => 'tribe', 'scope_id' => $t->id, 'scope_name' => $t->name, 'user_ids' => $patriarchs[$t->id]];
            }
        }
        foreach (Department::where('is_active', true)->with('leaders:id')->orderBy('name')->get(['id', 'name']) as $d) {
            if (! isset($done['department:'.$d->id]) && $d->leaders->isNotEmpty()) {
                $pending[] = ['kind' => 'department', 'scope_id' => $d->id, 'scope_name' => $d->name,
                    'user_ids' => $d->leaders->pluck('id')->map(fn ($id) => (int) $id)->all()];
            }
        }

        return $pending;
    }

    /**
     * @param  array<int>  $tribeIds
     * @return array<int, array<int>> patriarches (ids) de chaque tribu
     */
    private static function patriarchsByTribe(array $tribeIds): array
    {
        $out = [];
        $rows = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.key', 'patriarche')->where('role_user.scope_kind', 'tribe')
            ->whereIn('role_user.scope_id', $tribeIds ?: [0])->get(['role_user.scope_id', 'role_user.user_id']);
        foreach ($rows as $row) {
            $out[(int) $row->scope_id][] = (int) $row->user_id;
        }

        return $out;
    }

    /** @return array{0: Carbon, 1: Carbon} debut et fin du mois */
    private static function bounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$start, $start->copy()->endOfMonth()];
    }
}
