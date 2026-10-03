<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Evaluation;
use App\Models\Gem;
use App\Models\GemWeeklyReport;
use App\Models\MemberRequest;
use App\Models\Profile;
use App\Models\SpiritualEntry;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Support\EvaluationCatalog;
use App\Support\SpiritualCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Annuaire des responsables, toujours a jour (il se lit dans les roles et l'organisation) :
 * - autorites pastorales : Assistants Pasteurs, patriarches, responsables de departement, Gardes,
 *   chacun avec son appartenance (tribus, departements, GEM) ;
 * - responsables d'une tribu (AP, patriarche) : les patriarches et les Gardes de leurs tribus.
 * Fiche d'un Garde : son GEM, ses membres, ses rapports hebdomadaires et son activite de suivi.
 */
class LeaderDirectoryService
{
    /** Jours pris en compte pour l'activite de suivi d'un Garde. */
    public const ACTIVITY_DAYS = 60;

    /**
     * Tribus dont l'utilisateur voit les responsables : null = toute l'eglise.
     * Refuse les membres sans responsabilite sur une tribu.
     *
     * @return array<int>|null
     */
    public static function tribeScope(User $user): ?array
    {
        if ($user->hasPermission('members.view_all')) {
            return null;
        }
        $tribeIds = $user->hasPermission('members.view_scope') ? $user->scopeTribeIds() : [];
        abort_unless($tribeIds, 403, 'Cette page est réservée aux pasteurs et aux responsables de tribu.');

        return $tribeIds;
    }

    /** @return array<string, mixed> */
    public static function directory(User $user): array
    {
        $tribeIds = self::tribeScope($user);
        $all = $tribeIds === null;
        $tribes = Tribe::when(! $all, fn ($q) => $q->whereIn('id', $tribeIds))->orderBy('name')->get(['id', 'name'])->keyBy('id');

        $holders = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->whereIn('roles.key', $all ? ['assistant_pasteur', 'patriarche'] : ['patriarche'])
            ->where('role_user.scope_kind', 'tribe')->whereIn('role_user.scope_id', $tribes->keys())
            ->get(['roles.key', 'role_user.user_id', 'role_user.scope_id']);
        $gems = Gem::where('is_active', true)->whereIn('tribe_id', $tribes->keys())->withCount('members')->orderBy('name')->get();
        $departments = $all ? Department::where('is_active', true)->with('leaders:id')->orderBy('name')->get(['id', 'name']) : collect();

        // Identite de tous les responsables en une seule lecture.
        $userIds = $holders->pluck('user_id')->merge($gems->pluck('leader_user_id'))
            ->merge($departments->flatMap(fn (Department $d) => $d->leaders->pluck('id')))->filter()->unique()->values();
        $people = Profile::whereIn('user_id', $userIds)->with('user:id,phone', 'tribe:id,name')->get()->keyBy('user_id');
        $person = fn (int $userId) => [
            'user_id' => $userId,
            'name' => $people->get($userId)?->full_name ?: 'Membre #'.$userId,
            'photo_url' => $people->get($userId)?->photo_url,
            'phone' => $people->get($userId)?->user?->phone,
        ];
        // Une ligne par personne, avec toutes ses appartenances (un AP peut suivre plusieurs tribus).
        $grouped = fn (Collection $rows) => $rows->groupBy('user_id')
            ->map(fn (Collection $own, $userId) => $person((int) $userId) + [
                'tribes' => $own->map(fn ($r) => $tribes->get($r->scope_id)?->name)->filter()->unique()->sort()->values()->all(),
            ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        $due = GemReportService::dueWeek();
        $sent = GemWeeklyReport::where('week_start', $due->toDateString())->whereIn('gem_id', $gems->pluck('id'))->pluck('gem_id')->flip();
        $withPatriarch = $holders->where('key', 'patriarche')->pluck('scope_id')->map(fn ($id) => (int) $id)->flip();

        return [
            'scope' => $all ? 'church' : 'tribes',
            'tribes' => $tribes->values()->map(fn (Tribe $t) => ['id' => $t->id, 'name' => $t->name])->all(),
            'week_label' => GemReportService::weekLabel($due),
            'assistants' => $all ? $grouped($holders->where('key', 'assistant_pasteur')) : [],
            'patriarchs' => $grouped($holders->where('key', 'patriarche')),
            'tribes_without_patriarch' => $tribes->reject(fn (Tribe $t) => isset($withPatriarch[$t->id]))->pluck('name')->values()->all(),
            'department_leaders' => $departments->flatMap(fn (Department $d) => $d->leaders->map(fn (User $u) => ['user_id' => $u->id, 'department' => $d->name]))
                ->groupBy('user_id')->map(fn (Collection $own, $userId) => $person((int) $userId) + [
                    'departments' => $own->pluck('department')->unique()->sort()->values()->all(),
                ])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'gardes' => $gems->map(fn (Gem $g) => [
                'gem_id' => $g->id,
                'gem' => $g->name,
                'tribe' => $tribes->get($g->tribe_id)?->name,
                'members_count' => $g->members_count,
                'garde' => $g->leader_user_id ? $person((int) $g->leader_user_id) : null,
                'week_report' => ! $g->leader_user_id ? null : (isset($sent[$g->id]) ? 'submitted' : 'missing'),
            ])->sortBy([['tribe', 'asc'], ['gem', 'asc']])->values()->all(),
        ];
    }

    /**
     * Fiche d'un Garde : comment il mene son GEM, a partir de ce que l'application enregistre deja.
     *
     * @return array<string, mixed>
     */
    public static function gem(User $viewer, Gem $gem): array
    {
        abort_unless(GemReportService::canRead($viewer, $gem), 403, 'Ce GEM ne fait pas partie de votre périmètre.');
        $gem->load('tribe:id,name', 'leader.profile');
        $leaderId = $gem->leader_user_id ? (int) $gem->leader_user_id : null;
        $members = Profile::where('gem_id', $gem->id)->where('is_completed', true)
            ->with('user:id,phone,last_seen_at,last_login_at,activity_status,activity_override')
            ->orderBy('first_name')->orderBy('last_name')->get();
        $memberIds = $members->pluck('user_id')->map(fn ($id) => (int) $id);

        // --- Rapports hebdomadaires : semaines attendues depuis la nomination (8 au plus).
        $due = GemReportService::dueWeek();
        $appointed = $leaderId ? DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.key', 'garde')->where('role_user.user_id', $leaderId)
            ->where('role_user.scope_kind', 'gem')->where('role_user.scope_id', $gem->id)->value('role_user.created_at') : null;
        $firstEver = GemWeeklyReport::min('week_start');
        $from = collect([
            $due->copy()->subWeeks(GemReportService::HISTORY_WEEKS - 1),
            $appointed ? Carbon::parse($appointed)->startOfWeek(Carbon::MONDAY) : null,
            // Avant le tout premier rapport de l'eglise, rien n'etait attendu.
            $firstEver ? Carbon::parse($firstEver) : $due->copy(),
        ])->filter()->max();
        $reports = GemWeeklyReport::where('gem_id', $gem->id)
            ->where('week_start', '>=', $due->copy()->subWeeks(GemReportService::HISTORY_WEEKS - 1)->toDateString())
            ->orderByDesc('week_start')->get()->keyBy(fn (GemWeeklyReport $r) => substr((string) $r->week_start, 0, 10));
        // Un rapport envoye compte toujours, meme pour une semaine anterieure a la nomination.
        if ($reports->isNotEmpty()) {
            $from = min($from, Carbon::parse($reports->keys()->min()));
        }
        $weeks = [];
        for ($w = $due->copy(); $leaderId && $w->gte($from); $w->subWeek()) {
            $r = $reports->get($w->toDateString());
            $weeks[] = $r ? GemReportService::present($r) + ['status' => 'submitted']
                : ['week_start' => $w->toDateString(), 'label' => GemReportService::weekLabel($w), 'status' => 'missing'];
        }
        $sentReports = $reports->values();
        $rate = fn (int $part, int $total) => $total ? (int) round($part / $total * 100) : null;

        // Presences par membre sur les rapports recus.
        $presence = [];
        foreach ($sentReports as $r) {
            foreach ($r->attendance ?? [] as $row) {
                $presence[$row['user_id']]['culte'] = ($presence[$row['user_id']]['culte'] ?? 0) + (int) ! empty($row['culte']);
                $presence[$row['user_id']]['rencontre'] = ($presence[$row['user_id']]['rencontre'] ?? 0) + (int) ! empty($row['rencontre']);
            }
        }

        // --- FISS du mois en cours et du mois precedent : jamais pour le Garde lui-meme.
        $fissTribes = $viewer->fissTribeIds();
        $seesFiss = $fissTribes === null || in_array((int) $gem->tribe_id, $fissTribes, true);
        $period = now()->format('Y-m');
        $previous = now()->startOfMonth()->subMonth()->format('Y-m');
        $forms = SpiritualHealthForm::whereIn('user_id', $memberIds)->whereIn('period', [$period, $previous])->get(['user_id', 'period'])
            ->groupBy('period')->map(fn (Collection $c) => $c->pluck('user_id')->map(fn ($id) => (int) $id)->flip());

        // --- Activite de suivi du Garde (ce qu'il a lui-meme enregistre).
        $since = now()->subDays(self::ACTIVITY_DAYS);
        $timeline = collect();
        $counts = ['followups' => 0, 'evaluations' => 0, 'attendance_sheets' => 0, 'requests' => 0, 'welcomed' => 0];
        if ($leaderId) {
            $names = fn (Collection $ids) => Profile::whereIn('user_id', $ids->unique())->get(['user_id', 'first_name', 'last_name'])
                ->mapWithKeys(fn (Profile $p) => [(int) $p->user_id => $p->full_name]);

            $entries = SpiritualEntry::where('author_user_id', $leaderId)->where('member_user_id', '!=', $leaderId)
                ->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(100)->get();
            $evaluations = Evaluation::where('created_by', $leaderId)->where('created_at', '>=', $since)->orderByDesc('created_at')->limit(100)->get();
            $sheets = Attendance::where('recorded_by', $leaderId)->where('created_at', '>=', $since)
                ->selectRaw("attended_on, event, sum(case when status = 'present' then 1 else 0 end) as present, max(created_at) as at")
                ->groupBy('attended_on', 'event')->orderByDesc('at')->limit(100)->get();
            $requests = MemberRequest::where('replied_by', $leaderId)->where('replied_at', '>=', $since)->orderByDesc('replied_at')->limit(100)->get();
            $welcomed = Profile::where('welcomed_by', $leaderId)->where('welcomed_at', '>=', $since)->orderByDesc('welcomed_at')->limit(100)->get();
            $who = $names($entries->pluck('member_user_id')->merge($evaluations->pluck('user_id'))->merge($requests->pluck('user_id')));

            $counts = ['followups' => $entries->count(), 'evaluations' => $evaluations->count(), 'attendance_sheets' => $sheets->count(),
                'requests' => $requests->count(), 'welcomed' => $welcomed->count()];
            $item = fn (string $kind, mixed $at, string $label) => ['kind' => $kind, 'at' => Carbon::parse($at)->toIso8601String(), 'label' => $label];
            $timeline = $timeline
                ->concat($entries->map(fn (SpiritualEntry $e) => $item('followup', $e->created_at,
                    'Suivi : '.(SpiritualCatalog::ENTRY_TYPES[$e->type] ?? 'Autre').' · '.($who[$e->member_user_id] ?? 'un membre'))))
                ->concat($evaluations->map(fn (Evaluation $e) => $item('evaluation', $e->created_at,
                    'Note : '.(EvaluationCatalog::TYPES[$e->type] ?? 'Autre').' · '.($who[$e->user_id] ?? 'un membre'))))
                ->concat($sheets->map(fn ($s) => $item('attendance', $s->at,
                    'Appel : '.$s->event.' du '.Carbon::parse($s->attended_on)->locale('fr')->isoFormat('D MMMM').' · '.(int) $s->present.' présent(s)')))
                ->concat($requests->map(fn (MemberRequest $r) => $item('request', $r->replied_at, 'Réponse à une demande · '.($who[$r->user_id] ?? 'un membre'))))
                ->concat($welcomed->map(fn (Profile $p) => $item('welcome', $p->welcomed_at, 'Nouvel inscrit accueilli · '.$p->full_name)))
                ->concat($sentReports->map(fn (GemWeeklyReport $r) => $item('report', $r->submitted_at, 'Rapport de la semaine '.GemReportService::weekLabel($r->week_start).' envoyé')));
        }

        $sent = collect($weeks)->where('status', 'submitted');

        return [
            'gem' => ['id' => $gem->id, 'name' => $gem->name, 'tribe' => $gem->tribe?->name],
            'garde' => $leaderId ? [
                'user_id' => $leaderId,
                'name' => $gem->leader?->profile?->full_name ?: 'Membre #'.$leaderId,
                'photo_url' => $gem->leader?->profile?->photo_url,
                'phone' => $gem->leader?->phone,
                'since' => $appointed ? Carbon::parse($appointed)->toDateString() : null,
            ] : null,
            'indicators' => [
                'members' => $members->count(),
                'active' => $members->filter(fn (Profile $p) => $p->user?->activityStatus() === 'active')->count(),
                'reports_expected' => count($weeks),
                'reports_sent' => $sent->count(),
                'culte_rate' => $rate((int) $sentReports->sum('culte_count'), (int) $sentReports->sum('members_count')),
                'meetings_held' => $sentReports->where('meeting_held', true)->count(),
                'meeting_rate' => $rate((int) $sentReports->where('meeting_held', true)->sum('meeting_count'), (int) $sentReports->where('meeting_held', true)->sum('members_count')),
                'fiss_filled' => $seesFiss ? $memberIds->filter(fn ($id) => isset($forms[$period][$id]))->count() : null,
                'fiss_previous' => $seesFiss ? $memberIds->filter(fn ($id) => isset($forms[$previous][$id]))->count() : null,
                'activity_days' => self::ACTIVITY_DAYS,
            ] + $counts,
            'members' => $members->map(fn (Profile $p) => [
                'user_id' => (int) $p->user_id,
                'name' => $p->full_name,
                'photo_url' => $p->photo_url,
                'phone' => $p->user?->phone,
                'is_garde' => (int) $p->user_id === $leaderId,
                'status' => $p->user ? $p->user->activityStatus() : 'inactive',
                'fiss_current' => $seesFiss ? isset($forms[$period][(int) $p->user_id]) : null,
                'culte' => $presence[$p->user_id]['culte'] ?? 0,
                'rencontre' => $presence[$p->user_id]['rencontre'] ?? 0,
            ])->values()->all(),
            'reports_count' => $sentReports->count(),
            'weeks' => $weeks,
            'timeline' => $timeline->sortByDesc('at')->take(30)->values()->all(),
        ];
    }
}
