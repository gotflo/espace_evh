<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Department;
use App\Models\DepartmentJoinRequest;
use App\Models\User;
use App\Support\French;
use Illuminate\Support\Carbon;

/**
 * Courriels et textos aux membres (devoir, FISS, rapports) : courriel si l'adresse est remplie, sinon
 * texto. Chaque message a sa cle anti-doublon : un rappel n'est jamais envoye deux fois a la meme
 * personne, meme si l'automatisme repasse.
 */
class MemberMessages
{
    private static function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * Diffusion : membres (profil complete, actifs) charges par lots de 500, avec profil et preferences,
     * et une seule ecriture par lot (et non deux requetes par membre). Chaque message a sa cle anti-doublon :
     * relancer une diffusion interrompue n'envoie rien en double. Retourne le nombre de messages ajoutes.
     *
     * @param  callable(User): (array<string, mixed>|null)  $row  ligne de Messenger::memberRow
     */
    private static function each(iterable $userIds, callable $row): int
    {
        $count = 0;
        foreach (collect($userIds)->unique()->chunk(500) as $chunk) {
            $users = ActivityService::whereEffective(User::with('profile')->whereIn('id', $chunk->all()))
                ->whereHas('profile', fn ($p) => $p->where('is_completed', true))->get();
            $count += Messenger::queueMany($users->map($row)->filter()->values()->all());
        }

        return $count;
    }

    // ---------------------------------------------------------------- Devoir (DS)

    /** Nouveau devoir publie : chaque membre (sauf l'auteur) recoit un courriel, ou a defaut un texto. */
    public static function assignmentPublished(Assignment $a, iterable $userIds): int
    {
        $type = match ($a->media_type) {
            'audio' => ['Audio', 'l\'écouter'], 'video' => ['Vidéo', 'la regarder'], default => ['Devoir', 'le suivre']
        };
        $url = self::url('/mon-devoir?d='.$a->id);
        $title = mb_strlen($a->title) > 70 ? mb_substr($a->title, 0, 67).'...' : $a->title;

        return self::each($userIds, fn (User $u) => Messenger::memberRow($u, 'assignment', "assignment:{$a->id}:{$u->id}", [
            'subject' => 'Nouveau devoir : '.$title, // titre raccourci : un sujet trop long est coupe par les messageries
            'view' => 'assignment',
            'data' => [
                'firstName' => $u->profile?->first_name ?: 'cher membre',
                'title' => $a->title,
                'speaker' => $a->speaker,
                'typeLabel' => $type[0],
                'typeVerb' => $type[1],
                'instructions' => $a->instructions ? mb_substr($a->instructions, 0, 600) : null,
                'url' => $url,
            ],
        ], "My vasesdhonneur : nouveau devoir \"{$title}\". Ouvrez-le ici : {$url}", 'exercises'));
    }

    // ---------------------------------------------------------------- FISS

    /**
     * Rappel FISS du mois (stage « first » : premiere semaine ; « last » : derniere semaine) a ceux qui
     * ne l'ont pas encore envoyee.
     */
    public static function fissReminders(string $period, string $stage, iterable $userIds, int $daysLeft): int
    {
        $month = Carbon::parse($period.'-01')->locale('fr')->isoFormat('MMMM');
        $deMonth = French::de($month);
        $url = self::url('/ma-fiche');
        $sms = $stage === 'last'
            ? "My vasesdhonneur : votre fiche de sante spirituelle {$deMonth} n'est pas encore remplie. Il reste ".max(0, $daysLeft).' jour(s) : '.$url
            : "My vasesdhonneur : votre fiche de sante spirituelle {$deMonth} n'est pas encore remplie. Quelques minutes suffisent : {$url}";

        return self::each($userIds, fn (User $u) => Messenger::memberRow($u, 'fiss_reminder', "fiss:{$period}:{$stage}:{$u->id}", [
            'subject' => $stage === 'last' ? 'Rappel : votre fiche de santé spirituelle '.$deMonth : 'Votre fiche de santé spirituelle '.$deMonth,
            'view' => 'fiss-reminder',
            'data' => ['firstName' => $u->profile?->first_name ?: 'cher membre', 'stage' => $stage, 'deMonth' => $deMonth, 'daysLeft' => $daysLeft, 'url' => $url],
        ], $sms));
    }

    // ---------------------------------------------------------------- Absence

    /**
     * Plus d'une semaine sans connexion : ce qui attend le membre depuis sa derniere visite (devoirs,
     * exercices, annonces, notifications), sans reproche. Une fois par absence (cle avec la date de visite),
     * sauf relance manuelle (app:relance) qui fournit sa propre cle.
     *
     * @param  array<string, mixed>  $summary  WelcomeBackService::for()
     */
    public static function absence(User $user, int $days, array $summary, ?string $dedupe = null): ?string
    {
        $items = array_values(array_filter([
            $summary['assignments']['count'] ? self::count($summary['assignments']['count'], 'devoir à faire', 'devoirs à faire') : null,
            $summary['exercises']['count'] ? self::count($summary['exercises']['count'], 'exercice pour vous', 'exercices pour vous') : null,
            $summary['announcements']['count'] ? self::count($summary['announcements']['count'], 'nouvelle annonce', 'nouvelles annonces') : null,
            $summary['events']['count'] ? self::count($summary['events']['count'], 'nouvel événement', 'nouveaux événements') : null,
            $summary['unread'] ? self::count($summary['unread'], 'notification non lue', 'notifications non lues') : null,
        ]));
        $url = self::url('/tableau-de-bord');
        $seen = collect([$user->last_seen_at, $user->last_login_at])->filter()->max();

        return Messenger::toMember($user, 'absence', $dedupe ?? 'absence:'.$user->id.':'.$seen?->timestamp, [
            'subject' => 'Votre espace My vasesdhonneur vous attend',
            'view' => 'absence-reminder',
            'data' => [
                'firstName' => $user->profile?->first_name ?: '',
                'days' => $days,
                'connected' => $user->profile?->gender === 'femme' ? 'connectée' : ($user->profile?->gender === 'homme' ? 'connecté' : 'connecté(e)'),
                'items' => $items,
                'url' => $url,
            ],
        ], 'My vasesdhonneur : votre derniere visite remonte a '.$days.' jours. Votre espace vous attend : '.$url);
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n > 1 ? $many : $one);
    }

    // ---------------------------------------------------------------- Rapports des responsables

    /** Rapport du mois a transmettre (patriarche d'une tribu, responsable d'un departement). */
    public static function reportReminder(User $leader, string $period, string $stage, string $kind, int $scopeId, string $scopeName): bool
    {
        $month = LeaderReportService::periodLabel($period);
        $deMonth = French::de(preg_replace('/\s+\d{4}$/', '', $month) ?? $month);
        $scopeLabel = ($kind === 'tribe' ? 'tribu ' : 'département ').$scopeName;
        $url = self::url("/admin/rapports-mensuels?remplir={$kind}:{$scopeId}&mois={$period}");
        $closes = LeaderReportService::closesOn($period)->locale('fr')->isoFormat('dddd D MMMM');
        $leader->loadMissing('profile');

        return Messenger::toMember($leader, 'report_reminder', "report:{$period}:{$stage}:{$kind}:{$scopeId}:{$leader->id}", [
            'subject' => ($stage === 'last' ? 'Rappel : rapport ' : 'Rapport ').$deMonth.' à transmettre · '.ucfirst($scopeLabel),
            'view' => 'report-reminder',
            'data' => [
                'firstName' => $leader->profile?->first_name ?: 'cher responsable',
                'stage' => $stage, 'deMonth' => $deMonth, 'scopeLabel' => $scopeLabel,
                'scopeOf' => $kind === 'tribe' ? 'de la' : 'du', 'closesOn' => $closes, 'url' => $url,
            ],
        ], 'My vasesdhonneur : '.($stage === 'last' ? 'rappel, ' : '')."le rapport {$deMonth} ({$scopeLabel}) est a transmettre avant le {$closes} : "
            .self::url('/admin/rapports-mensuels')) !== null;
    }

    /**
     * Rappel du rapport mensuel pour un responsable de plusieurs groupes : un seul courriel qui les nomme tous
     * (un seul groupe : le courriel habituel).
     *
     * @param  array<int, array{kind: string, scope_id: int, scope_name: string}>  $scopes
     */
    public static function reportReminders(User $leader, string $period, string $stage, array $scopes): bool
    {
        if (count($scopes) === 1) {
            $s = $scopes[0];

            return self::reportReminder($leader, $period, $stage, $s['kind'], (int) $s['scope_id'], $s['scope_name']);
        }
        $month = LeaderReportService::periodLabel($period);
        $deMonth = French::de(preg_replace('/\s+\d{4}$/', '', $month) ?? $month);
        $closes = LeaderReportService::closesOn($period)->locale('fr')->isoFormat('dddd D MMMM');
        $leader->loadMissing('profile');
        $items = array_map(fn ($s) => [
            'label' => ucfirst(($s['kind'] === 'tribe' ? 'tribu ' : 'département ').$s['scope_name']),
            'url' => self::url("/admin/rapports-mensuels?remplir={$s['kind']}:{$s['scope_id']}&mois={$period}"),
        ], $scopes);
        $ids = array_map(fn ($s) => $s['kind'].$s['scope_id'], $scopes);
        sort($ids);

        return Messenger::toMember($leader, 'report_reminder', "report:{$period}:{$stage}:".implode(',', $ids).":{$leader->id}", [
            'subject' => ($stage === 'last' ? 'Rappel : ' : '').count($scopes).' rapports '.$deMonth.' à transmettre',
            'view' => 'report-reminder',
            'data' => [
                'firstName' => $leader->profile?->first_name ?: 'cher responsable',
                'stage' => $stage, 'deMonth' => $deMonth, 'closesOn' => $closes,
                'scopeLabel' => implode(', ', array_column($items, 'label')), 'scopeOf' => '',
                'items' => $items, 'url' => self::url('/admin/rapports-mensuels?mois='.$period),
            ],
        ], 'My vasesdhonneur : '.($stage === 'last' ? 'rappel, ' : '').count($scopes)." rapports {$deMonth} sont a transmettre avant le {$closes} : "
            .self::url('/admin/rapports-mensuels')) !== null;
    }

    // ---------------------------------------------------------------- Services (departements)

    /** Demande d'inscription a un service : courriel au responsable (prefs respectees), lien vers la validation. */
    public static function serviceRequest(User $leader, User $member, Department $department, DepartmentJoinRequest $request): bool
    {
        $email = Messenger::emailOf($leader);
        if (! $email || ! Messenger::wants($leader, 'email', 'service')) {
            return false;
        }
        $name = $member->profile?->full_name ?: (string) $member->phone;

        return Messenger::queueEmail($email, $leader->id, 'service_request', "Inscription au service {$department->name} : {$name}", 'service-request', [
            'firstName' => $leader->profile?->first_name ?: 'cher responsable',
            'memberName' => $name,
            'tribe' => $member->profile?->tribe?->name,
            'department' => $department->name,
            'requestedOn' => now()->locale('fr')->isoFormat('dddd D MMMM [à] H[h]mm'),
            'url' => self::url('/admin/validations#services'),
        ], "service-request:{$request->id}:{$leader->id}") !== null;
    }
}
