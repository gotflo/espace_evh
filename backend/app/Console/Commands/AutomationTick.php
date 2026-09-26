<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\Exercise;
use App\Models\ExerciseResponse;
use App\Models\MemberRequest;
use App\Models\Profile;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\CalendarService;
use App\Services\ExerciseProgress;
use App\Services\FissService;
use App\Services\Notifier;
use App\Services\ReportService;
use App\Support\Audience;
use App\Support\Blessings;
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

    protected $signature = 'app:tick {--only= : activity | event-reminders | service-digest | birthdays | weddings | tasks | fiss | profiles | monthly-report | followups | prune}';

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
            'profiles' => fn () => $this->profileReminders(),
            'monthly-report' => fn () => $this->monthlyReports(),
            'followups' => fn () => $this->followUps(),
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
            Cache::forever(self::STATUS_KEY, [
                'at' => now()->toIso8601String(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'steps' => $results,
            ]);
        }

        return self::SUCCESS;
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
                if ($minutes > self::SERVICE_LEAD_MINUTES || $event->all_day) {
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

                return Notifier::send([$leader->id], 'report', 'Rapport de '.$monthLabel.' · '.$report['scope']['label'],
                    $parts ? ucfirst(implode(' · ', $parts)).'.' : 'Le rapport du mois est disponible.',
                    '/admin/rapports?scope='.urlencode($scope).'&mois=6', ['period' => $previous->format('Y-m')]);
            });
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
        if ($now->hour < 18) {
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
                [$person['user_id']], 'birthday', 'Joyeux anniversaire'.($first ? ", {$first}" : '').' ! 🎂',
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
                    'Joyeux anniversaire de mariage ! 💍', Blessings::weddingMessage($names, (int) $person['user_id']), '/tableau-de-bord'));
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

        $daysLeft = (int) $now->copy()->startOfDay()->diffInDays($now->copy()->endOfMonth()->startOfDay());
        $type = match (true) {
            $daysLeft <= 1 => 'urgent',
            $now->day >= 20 => 'advance',
            default => null,
        };
        $period = $now->format('Y-m');
        if ($type) {
            $sent += $this->sendOnce("fiss:{$period}:{$type}", function () use ($period, $type, $now) {
                $filled = SpiritualHealthForm::where('period', $period)->pluck('user_id');
                $missing = User::whereHas('profile', fn ($p) => $p->where('is_completed', true))
                    ->where('activity_status', 'active')->whereNotIn('id', $filled)->pluck('id');
                $month = $now->locale('fr')->isoFormat('MMMM YYYY');

                return Notifier::send($missing, 'fiss', "Rappel : fiche de santé spirituelle de {$month}",
                    $type === 'urgent'
                        ? "Le mois se termine. Merci de remplir votre fiche de {$month} dès aujourd'hui."
                        : "Pensez à remplir votre fiche de {$month} avant la fin du mois.",
                    '/ma-fiche', ['period' => $period], $type === 'urgent' ? 'high' : 'normal');
            });
        }

        // Patriarches : membres sans fiche le mois precedent.
        if ($now->day <= 5 && $now->hour >= 9) {
            $previous = $now->copy()->startOfMonth()->subMonth();
            $prevPeriod = $previous->format('Y-m');
            $filled = SpiritualHealthForm::where('period', $prevPeriod)->pluck('user_id')->all();
            $missing = Profile::where('is_completed', true)->where('created_at', '<=', $previous->copy()->endOfMonth())
                ->whereNotIn('user_id', $filled ?: [0])
                ->whereHas('user', fn ($u) => $u->where('activity_status', 'active'))
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

    /** Profils incomplets : rappel au membre toutes les 2 semaines (a partir de 10 h), avec les informations manquantes. */
    private function profileReminders(): int
    {
        $now = now();
        // Une fois par jour : recalcul du taux de completion stocke (profils existants avant la
        // migration, regles modifiees...). Idempotent, sans toucher a updated_at.
        if (Notifier::once('profile-completion-sync:'.$now->toDateString())) {
            Profile::query()->chunkById(200, fn ($profiles) => ProfileCompletion::refreshMany($profiles));
        }
        if ($now->hour < 10) {
            return 0;
        }
        $slot = $now->format('o').'-'.intdiv((int) $now->format('W'), 2);
        $sent = 0;
        Profile::where('is_completed', true)->where('completion', '<', 100)
            ->whereHas('user', fn ($u) => $u->where('activity_status', 'active'))
            ->where('created_at', '<=', $now->copy()->subDays(2))
            ->chunkById(200, function ($profiles) use (&$sent, $slot) {
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

        $newcomers = Profile::where('is_completed', true)->whereNull('welcomed_at')
            ->whereBetween('created_at', [$now->copy()->subDays(30), $now->copy()->subDays(3)])
            ->with('user')->get();
        foreach ($newcomers as $p) {
            if ($p->user) {
                $sent += $this->sendOnce("newcomer:{$p->user_id}", fn () => Notifier::send(Recipients::watchersOf($p->user), 'member',
                    "À accueillir : {$p->full_name}", 'Inscrit depuis '.(int) $p->created_at->diffInDays($now, true).' jours, pas encore accueilli.',
                    '/admin/membres/'.$p->user_id, ['user_id' => $p->user_id]));
            }
        }

        $pending = MemberRequest::where('status', 'nouvelle')->whereNull('replied_at')
            ->where('created_at', '<=', $now->copy()->subHours(48))
            ->where('created_at', '>=', $now->copy()->subDays(30))->with('sender.profile')->get();
        foreach ($pending as $r) {
            if ($r->sender) {
                $name = $r->sender->profile?->full_name ?: $r->sender->phone;
                $sent += $this->sendOnce("request-reminder:{$r->id}", fn () => Notifier::send(Recipients::followersOf($r->sender, 'requests.handle'), 'request',
                    "Demande en attente : {$name}", 'Sans réponse depuis plus de 48 h.', '/admin/demandes', ['request_id' => $r->id], 'high'));
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
                    CalendarService::joinNames(array_slice($names, 0, 6)).(count($names) > 6 ? '…' : '').' · 3 mois sans connexion, FISS ni présence. Un appel peut faire la différence.',
                    '/admin/membres?statut=inactif'));
            }
        }

        return $sent;
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

        return 0;
    }
}
