<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Admin\NewMemberController;
use App\Models\Assignment;
use App\Models\DepartmentJoinRequest;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Exercise;
use App\Models\MemberRequest;
use App\Models\MonthlyResolution;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Models\VertumetreForm;
use App\Services\ActivityService;
use App\Services\AppointmentService;
use App\Services\AssignmentProgress;
use App\Services\CalendarService;
use App\Services\DepartmentJoinService;
use App\Services\ExerciseProgress;
use App\Services\FissService;
use App\Services\GemReportService;
use App\Services\LeaderReportService;
use App\Services\MemberMessages;
use App\Services\Messenger;
use App\Services\Monitoring\Monitor;
use App\Services\Notifier;
use App\Services\ReportService;
use App\Services\TribeChangeService;
use App\Services\VertumetreService;
use App\Services\WelcomeBackService;
use App\Support\Audience;
use App\Support\Audit;
use App\Support\Blessings;
use App\Support\French;
use App\Support\PlatformSettings;
use App\Support\ProfileCompletion;
use App\Support\Recipients;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Automatismes de la plateforme. Chaque etape est idempotente (journal anti-doublon
 * notification_dispatches, cle par destinataire et par jour) : la commande peut tourner
 * toutes les minutes sans jamais renvoyer deux fois le meme message ; un envoi en echec
 * libere sa cle et sera retente au passage suivant. Heures en fuseau APP_TIMEZONE.
 * Lancee par le cron Laravel (schedule:run) et, en secours, par l'activite de l'application.
 */
class AutomationTick extends Command
{
    /** Cle de cache du dernier passage complet (heure, duree, resultat de chaque etape). */
    public const STATUS_KEY = 'automation:last-run';

    protected $signature = 'app:tick {--only= : activity | event-reminders | service-digest | birthdays | weddings | tasks | fiss | vertumetre | profiles | email-invites | monthly-report | leader-reports | gem-reports | resolutions | assignments | appointments | followups | service-joins | rm-renew | absences | tribe-changes | push-outbox | send-outbox | prune}';

    protected $description = 'Activité des membres, rappels, anniversaires, FISS, profils, relances et nettoyage.';

    public function handle(): int
    {
        $steps = [
            'activity' => fn () => $this->activity(),
            'event-reminders' => fn () => $this->eventReminders(),
            'service-digest' => fn () => $this->serviceDigest(),
            'birthdays' => fn () => $this->birthdays(),
            'weddings' => fn () => $this->weddings(),
            'tasks' => fn () => $this->taskReminders(),
            'fiss' => fn () => $this->fiss(),
            'vertumetre' => fn () => $this->vertumetre(),
            'profiles' => fn () => $this->profileReminders(),
            // Adresse courriel manquante : une invitation, puis un seul rappel 3 semaines plus tard.
            'email-invites' => fn () => $this->emailInvites(),
            'monthly-report' => fn () => $this->monthlyReports(),
            'leader-reports' => fn () => $this->leaderReports(),
            'gem-reports' => fn () => $this->gemReports(),
            'resolutions' => fn () => $this->resolutionReminders(),
            'assignments' => fn () => $this->assignmentReminders(),
            // Rendez-vous avec le pasteur : liste du jour, rappels, cloture.
            'appointments' => fn () => AppointmentService::tick(),
            'followups' => fn () => $this->followUps(),
            // Inscriptions aux services : relance du responsable, validation automatique si choisie.
            'service-joins' => fn () => $this->serviceJoins(),
            // RM oubliees reprises le 15 (seulement si active dans les reglages).
            'rm-renew' => fn () => $this->renewForgottenResolutions(),
            // Plus d'une semaine sans connexion : courriel au membre, liste a son patriarche et a son AP.
            'absences' => fn () => $this->absences(),
            'tribe-changes' => fn () => TribeChangeService::completeApproved(),
            // Secours de app:push-outbox : aucun push ne reste bloque si seule cette tache tourne.
            'push-outbox' => fn () => Notifier::flushOutbox(),
            // Secours de app:send-outbox : courriels, textos et courriel du jour des pasteurs.
            'send-outbox' => function () {
                AppointmentService::mailDigests();

                return Messenger::flush()['sent'];
            },
            'prune' => fn () => $this->prune(),
        ];
        $only = $this->option('only');
        $started = microtime(true);
        $results = [];

        // Responsables charges une seule fois pour tout le passage (et non a chaque notification).
        Recipients::remember(function () use ($steps, $only, &$results) {
            foreach ($steps as $name => $step) {
                if ($only && $only !== $name) {
                    continue;
                }
                // Rappel suspendu dans les reglages de la plateforme (les etapes techniques ne le sont jamais).
                if (! PlatformSettings::stepEnabled($name)) {
                    $results[$name] = ['count' => 0, 'ms' => 0, 'paused' => true];

                    continue;
                }
                // Une etape en erreur n'empeche jamais les suivantes ; l'erreur est journalisee.
                $stepStart = microtime(true);
                try {
                    $count = $step();
                    $results[$name] = ['count' => (int) $count, 'ms' => (int) round((microtime(true) - $stepStart) * 1000)];
                    if ($count) {
                        $this->info("{$name} : {$count}.");
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $results[$name] = 'erreur : '.mb_substr($e->getMessage(), 0, 160);
                    $this->error("{$name} : ".$e->getMessage());
                }
            }
        });

        // Trace du dernier passage complet (verifiee par /api/health).
        if (! $only) {
            $failed = array_keys(array_filter($results, 'is_string'));
            Monitor::jobRun('app:tick', $started, $failed ? 'partial' : 'ok', ['steps' => $results]);
            if ($failed) {
                Monitor::event('error', 'automation', 'job.failed', 'Automatismes : étape(s) en échec : '.implode(', ', $failed),
                    ['steps' => array_intersect_key($results, array_flip($failed))], 'failure');
            }
            Cache::forever(self::STATUS_KEY, [
                'at' => now()->toIso8601String(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'steps' => $results,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Inscriptions aux services en attente : apres le delai choisi, le responsable est relance (une fois) ; si
     * les reglages le prevoient, la demande toujours sans reponse est ensuite acceptee automatiquement.
     */
    private function serviceJoins(): int
    {
        $now = now();
        if ($now->hour < 9 || $now->hour >= 20) {
            return 0;
        }
        $remind = PlatformSettings::int('services.reminder_after_days');
        $auto = PlatformSettings::int('services.auto_approve_after_days');
        $sent = 0;
        $pending = DepartmentJoinRequest::where('status', 'pending')
            ->where('created_at', '<=', $now->copy()->subDays($auto > 0 ? min($remind, $auto) : $remind))
            ->with('department', 'member.profile')->get();
        foreach ($pending as $request) {
            if (! $request->department || ! $request->member) {
                continue;
            }
            if ($auto > 0 && $request->created_at->lte($now->copy()->subDays($auto))) {
                $sent += DepartmentJoinService::approveAutomatically($request) ? 1 : 0;

                continue;
            }
            $sent += $this->sendOnce("service-join-reminder:{$request->id}", function () use ($request) {
                $name = $request->member->profile?->full_name ?: $request->member->phone;
                $deciders = array_values(array_diff(DepartmentJoinService::deciderIds($request->department), [$request->user_id]));

                return Notifier::send($deciders, 'service', "En attente : inscription de {$name}",
                    "Sa demande pour le service {$request->department->name} attend votre réponse.", '/admin/validations#services',
                    ['department_join_request_id' => $request->id]);
            });
        }

        return $sent;
    }

    /**
     * Le 15 du mois (a partir de 9 h), si les reglages le prevoient : les resolutions du mois precedent sont
     * reprises pour les membres actifs qui n'ont encore rien ecrit ; ils en sont prevenus et peuvent les modifier.
     */
    private function renewForgottenResolutions(): int
    {
        $now = now();
        if ($now->day < 15 || $now->hour < 9) {
            return 0;
        }
        $period = $now->format('Y-m');
        $previous = $now->copy()->subMonthNoOverflow()->format('Y-m');

        return $this->sendOnce("rm-renew:{$period}", function () use ($period, $previous) {
            $renewed = [];
            MonthlyResolution::where('period', $previous)
                ->whereNotIn('user_id', MonthlyResolution::where('period', $period)->select('user_id'))
                ->whereHas('user', fn ($u) => ActivityService::whereEffective($u)->where('approval_status', User::APPROVED)->whereNull('blocked_at'))
                ->chunkById(200, function ($rows) use ($period, &$renewed) {
                    foreach ($rows as $row) {
                        $filled = $row->filled();
                        if ($filled === []) {
                            continue;
                        }
                        $created = MonthlyResolution::firstOrCreate(['user_id' => $row->user_id, 'period' => $period], $filled + ['renewed' => array_keys($filled)]);
                        if ($created->wasRecentlyCreated) {
                            Audit::log('resolutions.auto_renewed', $created, $row->user_id, [], ['period' => $period], ['from' => $row->period]);
                            $renewed[] = $row->user_id;
                        }
                    }
                });
            if ($renewed) {
                $from = FissService::periodLabel($previous);
                $to = FissService::periodLabel($period);
                Notifier::send($renewed, 'resolution', 'Vos résolutions sont reprises',
                    "Vos résolutions de {$from} sont reprises pour {$to}. Vous pouvez les modifier à tout moment.", '/mes-resolutions');
            }

            return count($renewed);
        });
    }

    /** Envoi protege : une seule fois par cle ; en cas d'echec, la cle est liberee (nouvel essai). */
    private function sendOnce(string $key, callable $send): int
    {
        if (! Notifier::once($key)) {
            return 0;
        }
        try {
            return (int) $send();
        } catch (\Throwable $e) {
            Notifier::release($key);
            report($e);

            return 0;
        }
    }

    /** Statuts actif / inactif recalcules une fois par jour (et traces dans le journal d'audit). */
    private function activity(): int
    {
        if (! Notifier::once('activity:'.now()->toDateString())) {
            return 0;
        }
        $changes = ActivityService::refreshAll();

        return count($changes['inactivated']) + count($changes['reactivated']);
    }

    /** Rappel la veille (dans les 24 h) a l'audience, et ~1 h avant aux inscrits. */
    private function eventReminders(): int
    {
        $now = now();
        $sent = 0;
        foreach (CalendarService::occurrences(Event::query()->with('scopes'), $now, $now->copy()->addHours(24)) as $o) {
            /** @var Event $event */
            $event = $o['event'];
            /** @var Carbon $start */
            $start = $o['start'];
            $minutes = $now->diffInMinutes($start, false);
            if ($minutes <= 0) {
                continue;
            }
            $date = $start->toDateString();
            if ($event->remind_all && ! $event->is_personal) {
                if ($minutes > PlatformSettings::int('reminders.service_lead_minutes') || $event->all_day) {
                    continue;
                }
                $sent += $this->serviceReminder($event, $start, $date);

                continue;
            }
            $kind = $minutes <= 90 && ! $event->all_day ? 'hour' : 'day';

            // Evenement quotidien : pas de rappel « veille » (ce serait tous les jours).
            if ($kind === 'day' && $event->recurrence === 'daily') {
                continue;
            }
            // Tout juste cree : l'annonce « nouvel evenement » vient d'etre envoyee.
            if ($kind === 'day' && ! $event->is_personal && $event->created_at && $event->created_at->gt($now->copy()->subHours(3))) {
                Notifier::once("event:{$event->id}:{$date}:{$kind}");

                continue;
            }
            // Rappel « veille » : seulement en soiree, a partir de l'heure du programme du lendemain,
            // jamais la nuit (un evenement d'une journee le declenchait a minuit). Le rappel
            // « peu avant » n'est pas concerne.
            if ($kind === 'day' && ($now->hour < PlatformSettings::int('reminders.digest_hour') || $now->hour >= 22)) {
                continue;
            }

            $time = $event->all_day ? 'toute la journée' : 'à '.$start->format('H\hi');
            $where = $event->location ? ' · '.$event->location : '';
            $sent += $this->sendOnce("event:{$event->id}:{$date}:{$kind}", function () use ($event, $kind, $start, $now, $date, $time, $where) {
                $responses = EventParticipation::where('event_id', $event->id)->where('occurs_on', $date)->get(['user_id', 'response']);
                $data = ['event_id' => $event->id, 'date' => $date];
                if ($event->is_personal) {
                    $label = $kind === 'hour' ? 'Bientôt' : ($start->isSameDay($now) ? "Aujourd'hui" : 'Demain');

                    return Notifier::send([$event->created_by], 'event_reminder', "{$label} : {$event->title}", ucfirst($time).$where,
                        '/calendrier?date='.$date, $data, $kind === 'hour' ? 'high' : 'normal');
                }
                if ($kind === 'day') {
                    $absent = $responses->where('response', 'absent')->pluck('user_id')->all();
                    $label = $start->isSameDay($now) ? "Aujourd'hui" : 'Demain';

                    return Notifier::send(array_diff(Audience::userIds($event->audienceList()), $absent), 'event_reminder',
                        "{$label} : {$event->title}", ucfirst($time).$where, '/calendrier?date='.$date, $data);
                }

                return Notifier::send($responses->where('response', 'present')->pluck('user_id')->all(), 'event_reminder',
                    "Bientôt : {$event->title}", 'Commence '.$time.$where, '/calendrier?date='.$date, $data, 'high');
            });
        }

        return $sent;
    }

    /**
     * Debut de mois (du 1er au 3, a partir de 9 h) : chaque responsable qui a acces aux rapports
     * recoit les chiffres cles du mois ecoule pour son perimetre (eglise, ses tribus ou sa tribu),
     * avec un lien direct vers le rapport complet. Une seule fois par responsable et par mois.
     */
    private function monthlyReports(): int
    {
        $now = now();
        if ($now->day > 3 || $now->hour < 9) {
            return 0;
        }
        $previous = $now->copy()->subMonthNoOverflow();
        $monthLabel = $previous->locale('fr')->isoFormat('MMMM YYYY');
        $leaders = User::whereHas('roles.permissions', fn ($q) => $q->where('key', 'reports.view'))
            ->where(fn ($q) => $q->whereNull('activity_override')->orWhere('activity_override', '!=', 'inactive'))
            ->get();

        $sent = 0;
        foreach ($leaders as $leader) {
            $sent += $this->sendOnce("monthly-report:{$leader->id}:".$previous->format('Y-m'), function () use ($leader, $monthLabel, $previous) {
                $options = ReportService::options($leader);
                $scope = $options['church'] ? 'church' : ($options['mine'] ? 'mine' : (isset($options['tribes'][0]) ? 'tribe:'.$options['tribes'][0]['id'] : null));
                if (! $scope) {
                    return 0;
                }
                $report = ReportService::build($leader, $scope, 2);
                $month = collect($report['monthly'])->firstWhere('month', $previous->format('Y-m'));
                if (! $month) {
                    return 0;
                }
                $pct = fn ($v) => $v === null ? null : round((float) $v).' %';
                $parts = array_filter([
                    $month['fiss_rate'] !== null ? 'FISS remplies : '.$pct($month['fiss_rate']) : null,
                    $month['spiritual_score'] !== null ? 'vie spirituelle : '.$pct($month['spiritual_score']) : null,
                    $month['attendance_rate'] !== null ? 'assiduité : '.$pct($month['attendance_rate']) : null,
                    $month['new_members'] ? $month['new_members'].' nouveau(x) membre(s)' : null,
                    $report['kpis']['inactive'] ? $report['kpis']['inactive'].' membre(s) inactif(s)' : null,
                ]);

                return Notifier::send([$leader->id], 'report', 'Rapport '.French::de($monthLabel).' · '.$report['scope']['label'],
                    $parts ? ucfirst(implode(' · ', $parts)).'.' : 'Le rapport du mois est disponible.',
                    '/admin/rapports?scope='.urlencode($scope).'&mois=6', ['period' => $previous->format('Y-m')]);
            });
        }

        return $sent;
    }

    /**
     * Rapport mensuel des responsables (a partir de 9 h) : chaque patriarche et chaque responsable
     * de departement est invite a remplir le rapport du mois ecoule du 1er au 4, puis relance du
     * 5 au 10 s'il n'est toujours pas envoye. Une fois par personne, par rapport et par etape.
     */
    private function leaderReports(): int
    {
        // Rapport du mois ecoule : rappel la premiere semaine, relance la derniere semaine du mois (des 9 h),
        // aux patriarches et responsables qui ne l'ont pas encore envoye : notification, push, et courriel
        // (ou texto). Plus rien une fois le rapport envoye.
        $now = now();
        $daysLeft = (int) $now->copy()->startOfDay()->diffInDays($now->copy()->endOfMonth()->startOfDay());
        $stage = match (true) {
            $now->day <= 7 => 'first',
            $daysLeft <= 6 => 'last',
            default => null,
        };
        if (! $stage || $now->hour < 9) {
            return 0;
        }
        $period = LeaderReportService::duePeriod();
        $month = LeaderReportService::periodLabel($period);
        $late = $stage === 'last';

        $sent = 0;
        foreach (LeaderReportService::pending($period) as $scope) {
            $what = ($scope['kind'] === 'tribe' ? 'tribu ' : 'département ').$scope['scope_name'];
            foreach ($scope['user_ids'] as $userId) {
                $sent += $this->sendOnce("leader-report:{$period}:{$scope['kind']}:{$scope['scope_id']}:{$userId}:{$stage}",
                    function () use ($userId, $late, $month, $what, $scope, $period, $stage) {
                        $count = Notifier::send([$userId], 'leader_report',
                            ($late ? 'Rappel : rapport' : 'Rapport').' '.French::de($month).' à remplir',
                            ucfirst($what).' · un questionnaire guidé, quelques minutes suffisent.',
                            "/admin/rapports-mensuels?remplir={$scope['kind']}:{$scope['scope_id']}&mois={$period}",
                            ['period' => $period, 'kind' => $scope['kind'], 'scope_id' => $scope['scope_id']], $late ? 'high' : 'normal');
                        if ($leader = User::with('profile')->find($userId)) {
                            MemberMessages::reportReminder($leader, $period, $stage, $scope['kind'], $scope['scope_id'], $scope['scope_name']);
                        }

                        return $count;
                    });
            }
        }

        return $sent;
    }

    /**
     * Rapport hebdomadaire des Gardes : invitation le dimanche a partir de 18 h (ou le lundi matin),
     * relance le mardi ou le mercredi (a partir de 9 h) si le rapport de la semaine n'est pas envoye.
     * Une fois par Garde, par GEM, par semaine et par etape.
     */
    private function gemReports(): int
    {
        $now = now();
        $stage = match (true) {
            ($now->isSunday() && $now->hour >= 18) || ($now->isMonday() && $now->hour >= 9) => 'first',
            ($now->isTuesday() || $now->isWednesday()) && $now->hour >= 9 => 'late',
            default => null,
        };
        if (! $stage) {
            return 0;
        }
        $week = GemReportService::dueWeek();
        $label = GemReportService::weekLabel($week);

        $sent = 0;
        foreach (GemReportService::pending($week) as $gem) {
            $sent += $this->sendOnce("gem-report:{$gem->id}:{$week->toDateString()}:{$gem->leader_user_id}:{$stage}",
                fn () => Notifier::send([(int) $gem->leader_user_id], 'gem_report',
                    ($stage === 'late' ? 'Rappel : rapport' : 'Rapport').' de la semaine à envoyer',
                    "{$gem->name} · semaine {$label} · présences au culte et à la rencontre, une minute suffit.",
                    '/admin/rapport-gem', ['gem_id' => $gem->id, 'week_start' => $week->toDateString()], $stage === 'late' ? 'high' : 'normal'));
        }

        return $sent;
    }

    /** Rappel des rendez-vous reguliers (cultes) : minutes avant le debut. */
    public const SERVICE_LEAD_MINUTES = 35;

    /** Rappel ~30 min avant un rendez-vous « rappel a tous » : toute l'audience sauf les absents declares. */
    private function serviceReminder(Event $event, Carbon $start, string $date): int
    {
        return $this->sendOnce("event:{$event->id}:{$date}:soon", function () use ($event, $start, $date) {
            $absent = EventParticipation::where('event_id', $event->id)->where('occurs_on', $date)
                ->where('response', 'absent')->pluck('user_id')->all();
            $where = $event->location ? ' · '.$event->location : '';

            return Notifier::send(array_diff(Audience::userIds($event->audienceList()), $absent), 'event_reminder',
                'Bientôt : '.$event->title, 'Commence à '.$start->format('H\hi').$where,
                '/calendrier?date='.$date, ['event_id' => $event->id, 'date' => $date, 'kind' => 'service'], 'high');
        });
    }

    /**
     * Veille (a partir de 18 h) : un seul message recapitulant le programme du lendemain
     * (ex. dimanche : priere, culte, Healing Time, Bloom Light) au lieu d'un rappel par
     * rendez-vous. Les destinataires qui recoivent le meme programme sont regroupes.
     */
    private function serviceDigest(): int
    {
        $now = now();
        if ($now->hour < PlatformSettings::int('reminders.digest_hour')) {
            return 0;
        }
        $day = $now->copy()->addDay()->startOfDay();
        $date = $day->toDateString();
        $occurrences = CalendarService::occurrences(
            Event::query()->where('remind_all', true)->where('is_personal', false)->with('scopes'),
            $day, $day->copy()->endOfDay(),
        )->sortBy(fn ($o) => $o['start']->timestamp)->values();
        if ($occurrences->isEmpty()) {
            return 0;
        }

        // Lignes du programme de chaque destinataire.
        $lines = [];
        foreach ($occurrences as $o) {
            /** @var Event $event */
            $event = $o['event'];
            $absent = EventParticipation::where('event_id', $event->id)->where('occurs_on', $date)
                ->where('response', 'absent')->pluck('user_id')->flip();
            $label = ($event->all_day ? '' : $o['start']->format('H\hi').' ').$event->title;
            foreach (Audience::userIds($event->audienceList()) as $userId) {
                if (! isset($absent[$userId])) {
                    $lines[$userId][] = $label;
                }
            }
        }

        $groups = [];
        foreach ($lines as $userId => $items) {
            $groups[implode("\n", $items)][] = $userId;
        }

        $weekday = $day->locale('fr')->isoFormat('dddd');
        $sent = 0;
        foreach ($groups as $program => $userIds) {
            $items = explode("\n", $program);
            $title = count($items) > 1 ? "Demain {$weekday} : programme du culte" : "Demain {$weekday} : {$items[0]}";
            $sent += $this->sendOnce('service-digest:'.$date.':'.md5($program), fn () => Notifier::send(
                $userIds, 'event_reminder', $title, count($items) > 1 ? implode(' · ', $items) : 'Nous vous attendons !',
                '/calendrier?date='.$date, ['date' => $date, 'kind' => 'service']));
        }

        return $sent;
    }

    /**
     * Anniversaires du jour (a partir de 7 h) : message chaleureux avec verset au membre
     * (actif ou non : c'est aussi une occasion de reprendre contact), et un seul message
     * par responsable avec les anniversaires de ses membres (le statut inactif est signale).
     * 29 fevrier : fete le 28 les annees non bissextiles.
     */
    private function birthdays(): int
    {
        $now = now();
        if ($now->hour < 7) {
            return 0;
        }
        $today = $now->toDateString();
        $people = collect(CalendarService::birthdays($now->copy()->startOfDay(), $now->copy()->endOfDay()))->flatMap(fn ($b) => $b['people']);
        if ($people->isEmpty()) {
            return 0;
        }

        $sent = 0;
        $members = User::whereIn('id', $people->pluck('user_id'))->with('profile')->get()->keyBy('id');
        foreach ($people as $person) {
            $member = $members->get($person['user_id']);
            $first = $member?->profile?->first_name ?: '';
            $sent += $this->sendOnce("birthday:{$person['user_id']}:{$today}", fn () => Notifier::send(
                [$person['user_id']], 'birthday', 'Joyeux anniversaire'.($first ? ", {$first}" : '').' !',
                Blessings::birthdayMessage($first, (int) $person['user_id']), '/tableau-de-bord'));
        }

        // Responsables : un recapitulatif par personne.
        $byLeader = [];
        foreach ($people as $person) {
            $member = $members->get($person['user_id']);
            if (! $member) {
                continue;
            }
            foreach (Recipients::leadersOf($member) as $leaderId) {
                $byLeader[$leaderId][] = $person['name'].($member->activityStatus() === 'inactive' ? ' (inactif)' : '');
            }
        }
        foreach ($byLeader as $leaderId => $names) {
            $sent += $this->sendOnce("birthday-digest:{$leaderId}:{$today}", fn () => Notifier::send([$leaderId], 'birthday',
                "Anniversaire aujourd'hui : ".CalendarService::joinNames($names),
                'Pensez à lui/leur souhaiter une bonne fête.', '/calendrier?date='.$today));
        }

        return $sent;
    }

    /** Anniversaires de mariage (a partir de 8 h) : message aux deux conjoints + recapitulatif aux responsables. */
    private function weddings(): int
    {
        $now = now();
        if ($now->hour < 8) {
            return 0;
        }
        $today = $now->toDateString();
        $sent = 0;
        $byLeader = [];
        foreach (CalendarService::weddings($now->copy()->startOfDay(), $now->copy()->endOfDay()) as $w) {
            $names = implode(' et ', array_column($w['people'], 'name'));
            foreach ($w['people'] as $person) {
                $sent += $this->sendOnce("wedding:{$person['user_id']}:{$today}", fn () => Notifier::send([$person['user_id']], 'wedding',
                    'Joyeux anniversaire de mariage !', Blessings::weddingMessage($names, (int) $person['user_id']), '/tableau-de-bord'));
                if ($member = User::find($person['user_id'])) {
                    foreach (Recipients::leadersOf($member) as $leaderId) {
                        $byLeader[$leaderId][$w['key']] = $names;
                    }
                }
            }
        }
        foreach ($byLeader as $leaderId => $couples) {
            $sent += $this->sendOnce("wedding-digest:{$leaderId}:{$today}", fn () => Notifier::send([$leaderId], 'wedding',
                "Anniversaire de mariage aujourd'hui : ".CalendarService::joinNames(array_values($couples)),
                'Une belle occasion de bénir ces couples.', '/calendrier?date='.$today));
        }

        return $sent;
    }

    /**
     * Mes RM : du 1er au 3 du mois (des 9 h), invitation a prendre ses resolutions (et a faire le
     * bilan du mois ecoule) ; du 8 au 12 (des 10 h), une seule relance a ceux qui ne l'ont pas fait.
     */
    private function resolutionReminders(): int
    {
        $now = now();
        $period = $now->format('Y-m');
        $slot = match (true) {
            $now->day <= 3 && $now->hour >= 9 => 'start',
            $now->day >= 8 && $now->day <= 12 && $now->hour >= 10 => 'relance',
            default => null,
        };
        if (! $slot) {
            return 0;
        }

        return $this->sendOnce("resolutions:{$period}:{$slot}", function () use ($period, $slot, $now) {
            $taken = MonthlyResolution::where('period', $period)->pluck('user_id');
            $missing = User::whereHas('profile', fn ($p) => $p->where('is_completed', true))
                ->where(fn ($q) => ActivityService::whereEffective($q))->whereNotIn('id', $taken)->pluck('id');
            $month = $now->locale('fr')->isoFormat('MMMM');

            return Notifier::send($missing, 'resolution',
                $slot === 'start' ? 'Nouveau mois : vos résolutions '.French::de($month) : 'Vos résolutions '.French::de($month).' vous attendent',
                $slot === 'start'
                    ? 'À tes résolutions répondra le succès (Job 22:28). Faites le bilan du mois passé et prenez vos résolutions.'
                    : 'Quelques minutes suffisent : renouvelez ou écrivez vos résolutions du mois.',
                '/mes-resolutions', ['period' => $period]);
        });
    }

    /** Mon devoir : 3 jours apres la publication (10 h - 20 h), une relance a ceux qui ne l'ont pas encore fait. */
    private function assignmentReminders(): int
    {
        $now = now();
        if ($now->hour < 10 || $now->hour >= 20) {
            return 0;
        }
        $sent = 0;
        $after = PlatformSettings::int('assignments.reminder_after_days');
        $due = Assignment::published()->where('published_at', '<=', $now->copy()->subDays($after))
            ->where('published_at', '>=', $now->copy()->subDays($after + 7))->get();
        foreach ($due as $assignment) {
            $sent += $this->sendOnce("assignment-reminder:{$assignment->id}", function () use ($assignment) {
                // Pas encore fait : resume manquant ou lecture non terminee (memes regles que l'ecran du membre).
                $done = AssignmentProgress::doneUserIds($assignment);
                $missing = User::whereHas('profile', fn ($p) => $p->where('is_completed', true))
                    ->where(fn ($q) => ActivityService::whereEffective($q))->whereNotIn('id', $done)
                    ->when($assignment->created_by, fn ($q) => $q->where('id', '!=', $assignment->created_by))->pluck('id');

                return Notifier::send($missing, 'assignment', 'Votre devoir : '.$assignment->title,
                    'Prenez un moment pour le faire, puis écrivez votre résumé et vos résolutions.',
                    '/mon-devoir?d='.$assignment->id, ['assignment_id' => $assignment->id]);
            });
        }

        return $sent;
    }

    /** Taches (exercices) a rendre demain ou aujourd'hui, non faites (a partir de 9 h). */
    /**
     * Exercices : invitations nominatives a ceux qui ne l'ont pas termine (video regardee en
     * entier et/ou reponse envoyee), en journee (8 h - 21 h) :
     * - 2 jours apres la publication (s'il reste plus de 2 jours) ;
     * - la veille de la fermeture (moins de 24 h) ;
     * - dernier rappel moins de 3 h avant la fermeture.
     * A la fermeture, l'auteur recoit le bilan. Un exercice ferme n'accepte plus rien.
     */
    private function taskReminders(): int
    {
        $now = now();
        $sent = 0;
        $daytime = $now->hour >= 8 && $now->hour < 21;

        if ($daytime) {
            $open = Exercise::where('is_active', true)
                ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', $now))
                ->where('created_at', '>=', $now->copy()->subDays(60))
                ->with('scopes')->get();
            foreach ($open as $ex) {
                $hoursLeft = $ex->closes_at ? $now->diffInMinutes($ex->closes_at, false) / 60 : null;
                $stage = match (true) {
                    $hoursLeft !== null && $hoursLeft <= 3 => 'last',
                    $hoursLeft !== null && $hoursLeft <= 24 => 'eve',
                    $ex->created_at->lte($now->copy()->subDays(2)) && ($hoursLeft === null || $hoursLeft > 48) => 'nudge',
                    default => null,
                };
                if (! $stage) {
                    continue;
                }
                $sent += $this->sendOnce("task:{$ex->id}:{$stage}", function () use ($ex, $stage) {
                    $recipients = array_diff(Audience::userIds($ex->audienceList()), ExerciseProgress::doneUserIds($ex), [(int) $ex->created_by]);
                    [$title, $body] = $this->taskReminderText($ex, $stage);

                    return Notifier::send($recipients, 'task_reminder', $title, $body, '/exercices/'.$ex->id,
                        ['exercise_id' => $ex->id], $stage === 'last' ? 'high' : 'normal');
                });
            }
        }

        // Bilan a l'auteur des exercices fermes depuis moins de 3 jours.
        $closed = Exercise::whereNotNull('closes_at')->whereBetween('closes_at', [$now->copy()->subDays(3), $now])
            ->whereNotNull('created_by')->with('scopes')->get();
        foreach ($closed as $ex) {
            $sent += $this->sendOnce("task-closed:{$ex->id}", function () use ($ex) {
                $audience = array_diff(Audience::userIds($ex->audienceList()), [(int) $ex->created_by]);
                $done = count(array_intersect($audience, ExerciseProgress::doneUserIds($ex)));

                return Notifier::send([(int) $ex->created_by], 'task', 'Exercice fermé : '.$ex->title,
                    "{$done} fidèle(s) sur ".count($audience).' l\'ont terminé. Consultez le suivi détaillé.',
                    '/admin/exercices?suivi='.$ex->id, ['exercise_id' => $ex->id]);
            });
        }

        return $sent;
    }

    /** @return array{0: string, 1: string} */
    private function taskReminderText(Exercise $ex, string $stage): array
    {
        $what = $ex->isVideo()
            ? ($ex->needsResponse() ? 'vidéo à regarder en entier, puis consigne à rendre' : 'vidéo à regarder en entier')
            : 'exercice à rendre';
        $deadline = $ex->closes_at?->locale('fr');
        $when = $deadline ? ($deadline->isToday() ? "aujourd'hui à ".$deadline->format('H\\hi') : $deadline->isoFormat('dddd D MMMM [à] H[h]mm')) : null;

        return match ($stage) {
            'last' => ['Dernier rappel : « '.$ex->title.' »', ucfirst($what).". L'exercice se ferme {$when}."],
            'eve' => ['Rappel : « '.$ex->title.' »', ucfirst($what).". Il se ferme {$when}."],
            default => ['À faire : « '.$ex->title.' »', ucfirst($what).($when ? ", avant le {$when}." : '.')],
        };
    }

    /**
     * FISS :
     * - rappel aux membres le 20 du mois, puis rappel urgent les 2 derniers jours (a partir de 8 h) ;
     * - du 1er au 5 du mois (a partir de 9 h) : chaque patriarche recoit la liste de SES membres
     *   actifs qui n'ont pas rempli la fiche du mois precedent (a defaut AP, a defaut pasteurs) ;
     * - fenetres de modification expirees : fiches reverrouillees.
     */
    private function fiss(): int
    {
        $now = now();
        $sent = FissService::expireOpenRequests();
        if ($now->hour < 8) {
            return $sent;
        }

        // Un rappel la 2e semaine du mois (du 8 au 14), une relance la derniere semaine (des 10 h), a ceux qui
        // n'ont pas encore envoye leur FISS du mois : notification dans l'application, push, et courriel (ou
        // texto). Plus rien apres l'envoi.
        $daysLeft = (int) $now->copy()->startOfDay()->diffInDays($now->copy()->endOfMonth()->startOfDay());
        $type = match (true) {
            $now->day >= PlatformSettings::int('fiss.reminder_from_day') && $now->day <= PlatformSettings::int('fiss.reminder_from_day') + 6 => 'first',
            $daysLeft <= 6 => 'last',
            default => null,
        };
        $period = $now->format('Y-m');
        if ($type && $now->hour >= 10) {
            $sent += $this->sendOnce("fiss:{$period}:{$type}", function () use ($period, $type, $now, $daysLeft) {
                $filled = SpiritualHealthForm::where('period', $period)->pluck('user_id');
                $missing = User::whereHas('profile', fn ($p) => $p->where('is_completed', true))
                    ->where(fn ($q) => ActivityService::whereEffective($q))->whereNotIn('id', $filled)->pluck('id');
                $month = French::de($now->locale('fr')->isoFormat('MMMM'));

                $count = Notifier::send($missing, 'fiss', $type === 'last' ? 'Rappel : votre fiche '.$month.' à remplir' : 'Votre fiche '.$month.' vous attend',
                    $type === 'last'
                        ? 'Il reste '.max(0, $daysLeft).' jour(s) avant la fin du mois. Merci de remplir votre fiche de santé spirituelle.'
                        : 'Prenez quelques minutes pour remplir votre fiche de santé spirituelle '.$month.'.',
                    '/ma-fiche', ['period' => $period], $type === 'last' ? 'high' : 'normal');
                MemberMessages::fissReminders($period, $type, $missing, $daysLeft);

                return $count;
            });
        }

        // Patriarches : membres sans fiche le mois precedent.
        if ($now->day <= 5 && $now->hour >= 9) {
            $previous = $now->copy()->startOfMonth()->subMonth();
            $prevPeriod = $previous->format('Y-m');
            $filled = SpiritualHealthForm::where('period', $prevPeriod)->pluck('user_id')->all();
            $missing = Profile::where('is_completed', true)->where('created_at', '<=', $previous->copy()->endOfMonth())
                ->whereNotIn('user_id', $filled ?: [0])
                ->whereHas('user', fn ($u) => ActivityService::whereEffective($u))
                ->get(['user_id', 'first_name', 'last_name', 'tribe_id']);
            $month = $previous->locale('fr')->isoFormat('MMMM YYYY');
            foreach ($missing->groupBy(fn ($p) => (int) $p->tribe_id) as $tribeId => $list) {
                foreach (Recipients::tribeLeadersFor($tribeId ?: null, 'fiss.review') as $leaderId) {
                    $names = $list->pluck('full_name')->all();
                    $sent += $this->sendOnce("fiss-missing:{$prevPeriod}:{$leaderId}:{$tribeId}", fn () => Notifier::send([$leaderId], 'fiss',
                        count($names)." membre(s) sans FISS en {$month}",
                        CalendarService::joinNames(array_slice($names, 0, 8)).(count($names) > 8 ? '…' : '').' · Un encouragement peut les aider.',
                        '/admin/rapports', ['period' => $prevPeriod, 'tribe_id' => $tribeId], 'high'));
                }
            }
        }

        return $sent;
    }

    /**
     * Vertumetre (meme rythme que la FISS) : deverrouillages expires reverrouilles ; a partir du 20
     * (des 8 h) un rappel, puis le dernier jour une relance, a ceux qui ne l'ont pas rempli ce mois-ci.
     */
    private function vertumetre(): int
    {
        $now = now();
        $sent = VertumetreService::expireOpenRequests();
        if ($now->hour < 8) {
            return $sent;
        }
        $daysLeft = (int) $now->copy()->startOfDay()->diffInDays($now->copy()->endOfMonth()->startOfDay());
        $type = match (true) {
            $daysLeft <= 1 => 'urgent',
            $now->day >= 20 => 'advance',
            default => null,
        };
        if (! $type) {
            return $sent;
        }
        $period = $now->format('Y-m');

        return $sent + $this->sendOnce("vertumetre:{$period}:{$type}", function () use ($period, $type, $now) {
            $filled = VertumetreForm::where('period', $period)->pluck('user_id');
            $missing = User::whereHas('profile', fn ($p) => $p->where('is_completed', true))
                ->where(fn ($q) => ActivityService::whereEffective($q))->whereNotIn('id', $filled)->pluck('id');
            $month = $now->locale('fr')->isoFormat('MMMM YYYY');

            return Notifier::send($missing, 'fiss', 'Rappel : votre Vertumètre '.French::de($month),
                $type === 'urgent'
                    ? 'Le mois touche à sa fin. Prenez quelques minutes pour remplir votre Vertumètre '.French::de($month).'.'
                    : 'Pensez à remplir votre Vertumètre '.French::de($month).' avant la fin du mois.',
                '/vertumetre', ['period' => $period], $type === 'urgent' ? 'high' : 'normal');
        });
    }

    /** Profils incomplets : rappel au membre toutes les 2 semaines (a partir de 10 h), avec les informations manquantes. */
    /**
     * Membres sans adresse courriel (le courriel porte devoirs, rappels et messages importants) : une
     * invitation (notification et push, de 11 h a 19 h), puis un seul rappel 3 semaines plus tard ;
     * ensuite plus rien. Le suivi est garde sur le compte (pas de relance a l'infini).
     */
    private function emailInvites(): int
    {
        $now = now();
        if ($now->hour < 11 || $now->hour >= 19) {
            return 0;
        }
        $sent = 0;
        User::where(fn ($q) => ActivityService::whereEffective($q))
            ->whereHas('profile', fn ($p) => $p->where('is_completed', true)->where(fn ($q) => $q->whereNull('email')->orWhere('email', '')))
            ->select(['id', 'notification_prefs'])->chunkById(200, function ($users) use ($now, &$sent) {
                foreach ($users as $user) {
                    $prefs = is_array($user->notification_prefs) ? $user->notification_prefs : [];
                    $invites = array_values((array) ($prefs['email_invites'] ?? []));
                    $due = count($invites) === 0
                        || (count($invites) === 1 && Carbon::parse($invites[0])->lte($now->copy()->subDays(21)));
                    if (! $due) {
                        continue;
                    }
                    $first = count($invites) === 0;
                    $prefs['email_invites'] = array_merge($invites, [$now->toDateString()]);
                    $user->forceFill(['notification_prefs' => $prefs])->save();
                    $sent += Notifier::send([$user->id], 'profile',
                        $first ? 'Recevez vos rappels par courriel' : 'Votre adresse courriel nous manque',
                        'Ajoutez votre adresse en un instant : votre devoir, vos rappels de fiche et les messages importants arriveront aussi dans votre boîte courriel.',
                        '/tableau-de-bord?courriel=1');
                }
            });

        return $sent;
    }

    private function profileReminders(): int
    {
        $now = now();
        // Une fois par jour : recalcul du taux de completion stocke (profils existants avant la
        // migration, regles modifiees...). Idempotent, sans toucher a updated_at.
        if (Notifier::once('profile-completion-sync:'.$now->toDateString())) {
            Profile::query()->chunkById(200, fn ($profiles) => DB::transaction(fn () => ProfileCompletion::refreshMany($profiles)));
        }
        if ($now->hour < 10) {
            return 0;
        }
        $slot = $now->format('o').'-'.intdiv((int) $now->format('W'), 2);
        $sent = 0;
        Profile::where('is_completed', true)->where('completion', '<', 100)
            ->whereHas('user', fn ($u) => ActivityService::whereEffective($u))
            ->where('created_at', '<=', $now->copy()->subDays(2))
            // Une transaction par lot : une seule ecriture disque pour 200 rappels au lieu de 400
            // (mesure MySQL, 5 000 membres : 191 s -> quelques secondes). Anti-doublon inchange.
            ->chunkById(200, function ($profiles) use (&$sent, $slot) {
                DB::transaction(function () use ($profiles, &$sent, $slot) {
                    foreach ($profiles as $p) {
                        $sent += $this->sendOnce("profile-incomplete:{$p->user_id}:{$slot}", function () use ($p) {
                            $c = ProfileCompletion::for($p);
                            if (! $c['missing']) {
                                ProfileCompletion::refresh($p);

                                return 0;
                            }

                            return Notifier::send([$p->user_id], 'profile', "Complétez votre profil ({$c['percent']} %)",
                                'Il manque : '.implode(', ', array_slice(array_column($c['missing'], 'label'), 0, 4)).'.', '/mon-profil', [], 'low');
                        });
                    }
                });
            });

        return $sent;
    }

    /**
     * Relances de suivi (a partir de 9 h), pour que rien ne tombe dans l'oubli :
     * - nouvel inscrit non accueilli apres 3 jours -> ses responsables ;
     * - demande sans reponse apres 48 h -> ceux qui la traitent ;
     * - chaque lundi : membres devenus inactifs la semaine passee -> leurs responsables.
     */
    private function followUps(): int
    {
        $now = now();
        if ($now->hour < 9) {
            return 0;
        }
        $sent = 0;

        // Arrivee = validation du compte par le pasteur (nouveaux comptes), sinon inscription.
        $arrival = NewMemberController::ARRIVAL;
        $newcomers = Profile::where('is_completed', true)->whereNull('welcomed_at')
            ->select('profiles.*')->selectRaw("{$arrival} as arrived_at")
            ->whereRaw("{$arrival} between ? and ?", [$now->copy()->subDays(30)->toDateTimeString(), $now->copy()->subDays(PlatformSettings::int('followups.welcome_after_days'))->toDateTimeString()])
            ->with('user')->get();
        foreach ($newcomers->chunk(200) as $group) {
            DB::transaction(function () use ($group, $now, &$sent) {
                foreach ($group as $p) {
                    if ($p->user) {
                        $sent += $this->sendOnce("newcomer:{$p->user_id}", fn () => Notifier::send(Recipients::watchersOf($p->user), 'member',
                            "À accueillir : {$p->full_name}", 'Arrivé(e) depuis '.(int) NewMemberController::arrivedAt($p)->diffInDays($now, true).' jours, pas encore accueilli(e).',
                            '/admin/membres/'.$p->user_id, ['user_id' => $p->user_id]));
                    }
                }
            });
        }

        $pending = MemberRequest::where('status', 'nouvelle')->whereNull('replied_at')
            ->where('created_at', '<=', $now->copy()->subHours(PlatformSettings::int('followups.request_after_hours')))
            ->where('created_at', '>=', $now->copy()->subDays(30))->with('sender.profile')->get();
        foreach ($pending as $r) {
            if ($r->sender) {
                $name = $r->sender->profile?->full_name ?: $r->sender->phone;
                $sent += $this->sendOnce("request-reminder:{$r->id}", fn () => Notifier::send(Recipients::followersOf($r->sender, 'requests.handle'), 'request',
                    "Demande en attente : {$name}", 'Sans réponse depuis plus de '.PlatformSettings::int('followups.request_after_hours').' h.', '/admin/demandes', ['request_id' => $r->id], 'high'));
            }
        }

        if ($now->isMonday()) {
            $justInactive = User::where('activity_status', 'inactive')->whereNull('activity_override')
                ->whereBetween('activity_changed_at', [$now->copy()->subDays(7), $now])->with('profile')->get();
            $byLeader = [];
            foreach ($justInactive as $u) {
                foreach (Recipients::watchersOf($u) as $leaderId) {
                    $byLeader[$leaderId][] = $u->profile?->full_name ?: $u->phone;
                }
            }
            foreach ($byLeader as $leaderId => $names) {
                $sent += $this->sendOnce("inactive-digest:{$leaderId}:".$now->toDateString(), fn () => Notifier::send([$leaderId], 'activity',
                    count($names).' membre(s) devenu(s) inactif(s)',
                    CalendarService::joinNames(array_slice($names, 0, 6)).(count($names) > 6 ? '…' : '').' · '.PlatformSettings::int('members.inactive_after_months').' mois sans connexion, FISS ni présence. Un appel peut faire la différence.',
                    '/admin/membres?statut=inactif'));
            }
        }

        return $sent;
    }

    /**
     * Absence : un membre qui n'a pas ouvert l'application depuis plus d'une semaine recoit, une fois par
     * absence, un courriel (a defaut un texto) et une notification avec ce qui l'attend ; le patriarche et
     * l'AP de sa tribu recoivent la liste du jour dans l'application. Une fois par jour, a partir de 10 h.
     * Absences de 7 a 30 jours seulement (au-dela, le suivi d'inactivite prend le relais). Les autorites
     * pastorales ne sont pas concernees.
     */
    private function absences(): int
    {
        $now = now();
        if ($now->hour < 10 || $now->hour >= 19) {
            return 0;
        }

        return $this->sendOnce('absences:'.$now->toDateString(), function () use ($now) {
            $lastSeen = 'COALESCE(last_seen_at, last_login_at)';
            $authorities = Recipients::withPermission('members.view_all');
            $byLeader = [];
            $sent = 0;
            User::where('approval_status', User::APPROVED)->whereNull('blocked_at')
                ->whereHas('profile', fn ($p) => $p->where('is_completed', true))
                ->whereRaw("{$lastSeen} < ?", [$now->copy()->subDays(PlatformSettings::int('absences.after_days'))->toDateTimeString()])
                ->whereRaw("{$lastSeen} >= ?", [$now->copy()->subDays(30)->toDateTimeString()])
                ->where(fn ($q) => $q->whereNull('absence_notified_at')->orWhereRaw("absence_notified_at < {$lastSeen}"))
                // Compte valide il y a moins d'une semaine : la personne decouvre a peine son espace.
                ->where(fn ($q) => $q->whereNull('approval_decided_at')->orWhere('approval_decided_at', '<', $now->copy()->subDays(7)))
                ->whereNotIn('id', $authorities ?: [0])
                ->with('profile.tribe')->chunkById(100, function ($users) use ($now, &$byLeader, &$sent) {
                    foreach ($users as $user) {
                        $seen = collect([$user->last_seen_at, $user->last_login_at])->filter()->max();
                        // Reserve l'envoi (une seule fois par absence, meme si deux passages se croisent).
                        if (User::where('id', $user->id)->where(fn ($q) => $q->whereNull('absence_notified_at')->orWhere('absence_notified_at', '<', $seen))
                            ->update(['absence_notified_at' => $now]) !== 1) {
                            continue;
                        }
                        $days = (int) $seen->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());
                        MemberMessages::absence($user, $days, WelcomeBackService::for($user, $seen));
                        $sent += Notifier::send([$user->id], 'system', 'Votre espace vous attend',
                            'Votre dernière visite remonte à '.$days.' jours. Venez voir ce qui vous attend.', '/tableau-de-bord');
                        if ($user->profile?->tribe_id) {
                            foreach (Recipients::tribeRoleHolders((int) $user->profile->tribe_id, ['patriarche', 'assistant_pasteur']) as $leaderId) {
                                if ($leaderId !== $user->id) {
                                    $byLeader[$leaderId][] = $user->profile->full_name ?: $user->phone;
                                }
                            }
                        }
                    }
                });
            // Delai reel des reglages (et non « une semaine » ecrit en dur).
            $after = PlatformSettings::int('absences.after_days');
            $since = $after === 7 ? 'une semaine' : "{$after} jours";
            foreach ($byLeader as $leaderId => $names) {
                $sent += Notifier::send([$leaderId], 'activity',
                    count($names) > 1 ? count($names)." membres sans connexion depuis {$since}" : $names[0]." : aucune connexion depuis {$since}",
                    (count($names) > 1 ? CalendarService::joinNames(array_slice($names, 0, 6)).(count($names) > 6 ? '…' : '').' · ' : '')
                    ."Pas de connexion depuis plus de {$after} jours. Un message ou un appel peut les encourager.",
                    '/admin/membres?statut=absents');
            }

            return $sent;
        });
    }

    /** Nettoyage quotidien : notifications lues de plus de 60 jours, toutes celles de plus de 6 mois. */
    private function prune(): int
    {
        if (! Notifier::once('prune:'.now()->toDateString())) {
            return 0;
        }
        DB::table('user_notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(60))->delete();
        DB::table('user_notifications')->where('created_at', '<', now()->subMonths(6))->delete();
        DB::table('notification_dispatches')->where('sent_at', '<', now()->subMonths(4))->delete();
        // Mesures de supervision au-dela de la duree maximale (la console purge plus tot selon ses reglages).
        Monitor::prune();

        return 0;
    }
}
