<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Gem;
use App\Models\GemWeeklyReport;
use App\Models\Profile;
use App\Models\User;
use App\Support\Audit;
use App\Support\Recipients;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rapport hebdomadaire des Gardes (un par GEM et par semaine, du lundi au dimanche) :
 * - presence de chaque membre du GEM au culte du dimanche et a la rencontre du GEM, plus un mot ;
 * - le rapport d'une semaine se remplit a partir de son dimanche et jusqu'au samedi suivant
 *   (il reste modifiable pendant ce delai, puis il est fige) ;
 * - a l'envoi, le patriarche et l'Assistant Pasteur de la tribu sont prevenus ;
 * - lecture : le Garde, les responsables de la tribu (patriarche, AP), les autorites pastorales.
 */
class GemReportService
{
    /** Semaines affichees dans l'historique et prises en compte dans les indicateurs. */
    public const HISTORY_WEEKS = 8;

    /** Lundi de la semaine dont le rapport est attendu : la semaine en cours le dimanche, sinon la precedente. */
    public static function dueWeek(): Carbon
    {
        $monday = now()->startOfWeek(Carbon::MONDAY);

        return now()->isSunday() ? $monday : $monday->subWeek();
    }

    /** « du 21 au 27 septembre 2026 » (ou « du 28 septembre au 4 octobre 2026 »). */
    public static function weekLabel(Carbon|string $weekStart): string
    {
        $start = Carbon::parse($weekStart)->locale('fr');
        $end = $start->copy()->addDays(6)->locale('fr');

        return 'du '.$start->isoFormat($start->month === $end->month ? 'D' : 'D MMMM').' au '.$end->isoFormat('D MMMM YYYY');
    }

    /** GEMs dont l'utilisateur est le Garde. */
    public static function ledGems(User $user): Collection
    {
        return Gem::where('is_active', true)
            ->where(fn ($q) => $q->where('leader_user_id', $user->id)->orWhereIn('id', self::gardeGemIds($user) ?: [0]))
            ->with('tribe:id,name')->orderBy('name')->get();
    }

    public static function canFill(User $user, Gem $gem): bool
    {
        return (int) $gem->leader_user_id === $user->id || in_array($gem->id, self::gardeGemIds($user), true);
    }

    /** Lecture des rapports d'un GEM : son Garde, les responsables de sa tribu, les autorites pastorales. */
    public static function canRead(User $user, Gem $gem): bool
    {
        return self::canFill($user, $gem)
            || $user->hasPermission('members.view_all')
            || ($user->hasPermission('members.view_scope') && in_array((int) $gem->tribe_id, $user->scopeTribeIds(), true));
    }

    /** Membres du GEM (profils completes), le Garde compris s'il en fait partie. */
    public static function members(Gem $gem): Collection
    {
        return Profile::where('gem_id', $gem->id)->where('is_completed', true)
            ->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * Membres deja pointes presents a un culte le dimanche de cette semaine (feuille de presence) :
     * les cases sont precochees, le Garde n'a pas a saisir deux fois.
     *
     * @return array<int>
     */
    public static function cultePrefill(Gem $gem, Carbon $weekStart): array
    {
        return Attendance::where('kind', 'culte')->where('status', 'present')
            ->where('attended_on', $weekStart->copy()->addDays(6)->toDateString())
            ->whereIn('member_user_id', Profile::where('gem_id', $gem->id)->select('user_id'))
            ->pluck('member_user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Envoie (ou corrige) le rapport de la semaine attendue.
     *
     * @param  array<int, array{user_id: int, culte?: bool, rencontre?: bool}>  $attendance
     */
    public static function save(User $author, Gem $gem, string $weekStart, bool $meetingHeld, array $attendance, ?string $comment): GemWeeklyReport
    {
        abort_unless(self::canFill($author, $gem), 403, "Ce GEM n'est pas à votre charge.");
        $due = self::dueWeek();
        abort_unless($weekStart === $due->toDateString(), 423, 'Le rapport de cette semaine ne peut plus être modifié.');

        $given = collect($attendance)->keyBy(fn ($row) => (int) ($row['user_id'] ?? 0));
        $rows = self::members($gem)->map(fn (Profile $p) => [
            'user_id' => (int) $p->user_id,
            'name' => $p->full_name,
            'culte' => (bool) ($given[$p->user_id]['culte'] ?? false),
            // Sans rencontre cette semaine, personne n'y est compte present.
            'rencontre' => $meetingHeld && (bool) ($given[$p->user_id]['rencontre'] ?? false),
        ])->values();
        abort_if($rows->isEmpty(), 422, "Ce GEM n'a pas encore de membre : ajoutez-les avant d'envoyer le rapport.");

        try {
            [$report, $first] = DB::transaction(function () use ($author, $gem, $due, $meetingHeld, $rows, $comment) {
                $report = GemWeeklyReport::where('gem_id', $gem->id)->where('week_start', $due->toDateString())->lockForUpdate()->first();
                $first = $report === null;
                $report ??= new GemWeeklyReport(['gem_id' => $gem->id, 'week_start' => $due->toDateString(), 'submitted_at' => now()]);
                $report->fill([
                    'author_user_id' => $author->id,
                    'meeting_held' => $meetingHeld,
                    'attendance' => $rows->all(),
                    'members_count' => $rows->count(),
                    'culte_count' => $rows->where('culte', true)->count(),
                    'meeting_count' => $rows->where('rencontre', true)->count(),
                    'comment' => ($comment = trim((string) $comment)) !== '' ? mb_substr($comment, 0, 2000) : null,
                ])->save();
                Audit::log($first ? 'gem_report.submitted' : 'gem_report.updated', $report, null, [], [
                    'culte' => $report->culte_count.'/'.$report->members_count,
                    'rencontre' => $meetingHeld ? $report->meeting_count.'/'.$report->members_count : 'pas de rencontre',
                ], ['gem' => $gem->name, 'week' => $due->toDateString()], $author);

                return [$report, $first];
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Ce rapport vient d\'être envoyé depuis un autre appareil. Rechargez la page.');
        }

        if ($first) {
            Notifier::send(array_diff(Recipients::tribeRoleHolders((int) $gem->tribe_id, ['patriarche', 'assistant_pasteur']), [$author->id]),
                'gem_report', 'Rapport de GEM reçu · '.$gem->name,
                'Semaine '.self::weekLabel($due).' · culte : '.$report->culte_count.' sur '.$report->members_count
                    .($meetingHeld ? ' · rencontre : '.$report->meeting_count.' sur '.$report->members_count : ' · pas de rencontre'),
                '/admin/responsables?gem='.$gem->id, ['gem_id' => $gem->id, 'week_start' => $due->toDateString()]);
        }

        return $report;
    }

    /**
     * GEMs (avec un Garde) dont le rapport de cette semaine n'est pas envoye.
     *
     * @return Collection<int, Gem>
     */
    public static function pending(Carbon $weekStart): Collection
    {
        return Gem::where('is_active', true)->whereNotNull('leader_user_id')
            ->whereNotIn('id', GemWeeklyReport::where('week_start', $weekStart->toDateString())->select('gem_id'))
            ->whereHas('members', fn ($q) => $q->where('is_completed', true))
            ->get();
    }

    /** @return array<string, mixed> */
    public static function present(GemWeeklyReport $r): array
    {
        return [
            'id' => $r->id,
            'week_start' => substr((string) $r->week_start, 0, 10),
            'label' => self::weekLabel($r->week_start),
            'meeting_held' => $r->meeting_held,
            'attendance' => $r->attendance ?? [],
            'members_count' => $r->members_count,
            'culte_count' => $r->culte_count,
            'meeting_count' => $r->meeting_count,
            'comment' => $r->comment,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
        ];
    }

    /** @return array<int> GEMs sur lesquels l'utilisateur a le role Garde */
    private static function gardeGemIds(User $user): array
    {
        return $user->roles->where('key', 'garde')->where('pivot.scope_kind', 'gem')
            ->pluck('pivot.scope_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
