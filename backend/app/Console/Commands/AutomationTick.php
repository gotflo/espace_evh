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
use App\Services\FissService;
use App\Services\Notifier;
use App\Support\Audience;
use App\Support\Blessings;
use App\Support\ProfileCompletion;
use App\Support\Recipients;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
    protected $signature = 'app:tick {--only= : activity | event-reminders | birthdays | weddings | tasks | fiss | profiles | followups | prune}';

    protected $description = 'Activité des membres, rappels, anniversaires, FISS, profils, relances et nettoyage.';

    public function handle(): int
    {
        $steps = [
            'activity' => fn () => $this->activity(),
            'event-reminders' => fn () => $this->eventReminders(),
            'birthdays' => fn () => $this->birthdays(),
            'weddings' => fn () => $this->weddings(),
            'tasks' => fn () => $this->taskReminders(),
            'fiss' => fn () => $this->fiss(),
            'profiles' => fn () => $this->profileReminders(),
            'followups' => fn () => $this->followUps(),
            'prune' => fn () => $this->prune(),
        ];
        $only = $this->option('only');

        foreach ($steps as $name => $step) {
            if ($only && $only !== $name) {
                continue;
            }
            try {
                $count = $step();
                if ($count) {
                    $this->info("{$name} : {$count}.");
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$name} : ".$e->getMessage());
            }
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
    private function taskReminders(): int
    {
        $now = now();
        if ($now->hour < 9) {
            return 0;
        }
        $sent = 0;
        $dates = ['today' => $now->toDateString(), 'tomorrow' => $now->copy()->addDay()->toDateString()];
        foreach ($dates as $when => $date) {
            foreach (Exercise::where('is_active', true)->whereDate('due_date', $date)->with('scopes')->get() as $ex) {
                $sent += $this->sendOnce("task:{$ex->id}:{$when}:{$date}", function () use ($ex, $when) {
                    $done = ExerciseResponse::where('exercise_id', $ex->id)->pluck('user_id')->all();
                    $recipients = array_diff(Audience::userIds($ex->audienceList()), $done, [$ex->created_by]);

                    return Notifier::send($recipients, 'task_reminder',
                        ($when === 'today' ? "À rendre aujourd'hui : " : 'À rendre demain : ').$ex->title,
                        "Vous n'avez pas encore répondu à cet exercice.", '/tableau-de-bord#exercices',
                        ['exercise_id' => $ex->id], $when === 'today' ? 'high' : 'normal');
                });
            }
        }

        return $sent;
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
            Profile::query()->chunkById(200, function ($profiles) {
                foreach ($profiles as $p) {
                    ProfileCompletion::refresh($p);
                }
            });
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
