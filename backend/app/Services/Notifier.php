<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Services\Monitoring\Monitor;
use App\Services\Push\WebPush;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Point d'entree unique des notifications : ecrit la notification dans le centre de
 * notifications de chaque destinataire puis l'envoie en push sur ses appareils abonnes.
 * Le push part apres la reponse HTTP (defer) pour ne pas ralentir l'application. Les automatismes
 * ecrivent seulement dans la boite d'envoi (push_outbox) : flushOutbox envoie tout ensuite, par lots.
 */
class Notifier
{
    /** Types de notifications (cle => libelle), utilises aussi par l'interface. */
    public const TYPES = [
        'announcement' => 'Annonce',
        'event' => 'Événement',
        'event_reminder' => 'Rappel',
        'task' => 'Tâche',
        'task_reminder' => 'Tâche',
        'request' => 'Demande',
        'request_reply' => 'Réponse',
        'evaluation' => 'Note',
        'role' => 'Fonction',
        'member' => 'Nouveau membre',
        'service' => 'Service',
        'birthday' => 'Anniversaire',
        'fiss' => 'FISS',
        'fiss_request' => 'FISS',
        'tribe_change' => 'Tribu',
        'family' => 'Famille',
        'profile' => 'Profil',
        'wedding' => 'Anniversaire de mariage',
        'activity' => 'Suivi',
        'report' => 'Rapport',
        'leader_report' => 'Rapport mensuel',
        'gem_report' => 'Rapport de GEM',
        'assignment' => 'Devoir',
        'resolution' => 'Résolutions',
        'appointment' => 'Rendez-vous',
        'system' => 'Information',
    ];

    /**
     * Au-dela de ce nombre de notifications dans l'heure, plus de push (sauf urgence) : le reste
     * attend dans la cloche. Evite qu'une journee chargee fasse vibrer le telephone sans arret.
     */
    public const PUSH_HOURLY_LIMIT = 6;

    /** Destinataires traites par lot : le curseur et l'heure de reservation sont ecrits apres chaque lot. */
    private const PUSH_LOT_USERS = 100;

    /** Elements de la boite d'envoi reserves a la fois par un passage du cron. */
    private const PUSH_LOT_ROWS = 100;

    /** Temps donne a un passage du cron : au-dela, le reste repart au passage suivant, sans renvoi. */
    public const PUSH_BUDGET_SECONDS = 45;

    /** Temps donne a l'envoi apres une reponse web (limite PHP : 300 s) ; le cron reprend au curseur. */
    private const PUSH_WEB_BUDGET_SECONDS = 240;

    /** Automatismes en cours : les push vont seulement dans la boite d'envoi, jamais un par un. */
    private static bool $outboxOnly = false;

    /**
     * Pendant $work, les push sont seulement ecrits dans la boite d'envoi. Ils partent ensuite tous par
     * flushOutbox, par lots (plus de 5 000 envois l'un apres l'autre pendant un passage d'app:tick).
     */
    public static function outboxOnly(callable $work): mixed
    {
        $previous = self::$outboxOnly;
        self::$outboxOnly = true;
        try {
            return $work();
        } finally {
            self::$outboxOnly = $previous;
        }
    }

    /**
     * Categories que chaque membre peut couper en push (la notification reste dans la cloche).
     * Les types absents de cette liste (FISS, reponses, famille, fonctions...) ne se coupent pas.
     */
    public const PREF_CATEGORIES = [
        'services' => 'Rappels des cultes',
        'events' => 'Événements et leurs rappels',
        'announcements' => 'Annonces',
        'exercises' => 'Exercices, devoirs, résolutions et leurs rappels',
        'birthdays' => 'Anniversaires',
        'followup' => 'Suivi des membres (responsables)',
    ];

    /** Categorie de preference d'une notification (null = essentielle, jamais coupee). */
    public static function prefCategory(string $type, array $data = []): ?string
    {
        return match ($type) {
            'event_reminder' => ($data['kind'] ?? null) === 'service' ? 'services' : 'events',
            'event' => 'events',
            'announcement' => 'announcements',
            'task', 'task_reminder', 'assignment', 'resolution' => 'exercises',
            'birthday', 'wedding' => 'birthdays',
            'member', 'activity', 'report', 'request' => 'followup',
            default => null,
        };
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $data
     * @return int nombre de destinataires
     */
    public static function send(iterable $userIds, string $type, string $title, ?string $body = null, ?string $url = null, array $data = [], string $urgency = 'normal'): int
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        $title = mb_substr($title, 0, 250);
        $now = now();
        $json = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;

        foreach ($ids->chunk(500) as $chunk) {
            DB::table('user_notifications')->insert($chunk->map(fn ($id) => [
                'user_id' => $id,
                'type' => $type,
                'priority' => $urgency === 'high' ? 'high' : ($urgency === 'low' ? 'low' : 'normal'),
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'data' => $json,
                'created_at' => $now,
            ])->all());
        }

        // Push : sauf pour les membres qui ont coupe cette categorie.
        $category = self::prefCategory($type, $data);
        $pushIds = $ids;
        if ($category) {
            $muted = [];
            foreach ($ids->chunk(500) as $chunk) {
                foreach (DB::table('users')->whereIn('id', $chunk)->whereNotNull('notification_prefs')->pluck('notification_prefs', 'id') as $id => $prefs) {
                    if ((json_decode((string) $prefs, true)[$category] ?? true) === false) {
                        $muted[] = (int) $id;
                    }
                }
            }
            $pushIds = $ids->diff($muted)->values();
        }

        // Limitation (anti-spam) : au-dela de PUSH_HOURLY_LIMIT notifications dans l'heure pour une
        // meme personne, les suivantes restent dans la cloche sans faire vibrer le telephone
        // (sauf urgence). Le compte inclut celle qui vient d'etre enregistree.
        if ($urgency !== 'high' && $pushIds->isNotEmpty()) {
            $busy = [];
            foreach ($pushIds->chunk(500) as $chunk) {
                $busy = array_merge($busy, DB::table('user_notifications')->whereIn('user_id', $chunk)
                    ->where('created_at', '>=', $now->copy()->subHour())
                    ->groupBy('user_id')->havingRaw('count(*) > ?', [PlatformSettings::int('push.hourly_limit')])->pluck('user_id')->all());
            }
            $pushIds = $pushIds->diff(array_map('intval', $busy))->values();
        }

        if (config('services.webpush.enabled') && $pushIds->isNotEmpty()) {
            self::push($pushIds->all(), [
                'priority' => $urgency,
                'title' => $title,
                'body' => $body ? mb_substr($body, 0, 240) : '',
                'url' => $url ?: '/tableau-de-bord',
                'type' => $type,
                'tag' => $type.'-'.$now->timestamp,
            ], $urgency);
        }

        return $ids->count();
    }

    /** Cette cle a-t-elle deja ete posee ? (sans la poser) */
    public static function alreadyDone(string $key): bool
    {
        return DB::table('notification_dispatches')->where('key', mb_substr($key, 0, 191))->exists();
    }

    /** Libere une cle (envoi echoue : il sera retente au prochain passage). */
    public static function release(string $key): void
    {
        DB::table('notification_dispatches')->where('key', mb_substr($key, 0, 191))->delete();
    }

    /**
     * Anti-doublon pour les envois automatiques : retourne true la premiere fois qu'une
     * cle est vue (et la memorise), false ensuite.
     */
    public static function once(string $key): bool
    {
        // Sans erreur quand la cle existe deja : un passage rejoue des milliers de cles deja vues.
        return DB::table('notification_dispatches')->insertOrIgnore(['key' => mb_substr($key, 0, 191), 'sent_at' => now()]) === 1;
    }

    /**
     * Push : enregistre dans la boite d'envoi (push_outbox), puis envoye tout de suite.
     * Si l'envoi immediat n'aboutit pas (processus coupe par l'hebergeur apres la reponse,
     * service push injoignable), le cron le rattrape (app:push-outbox, chaque minute).
     *
     * @param  array<int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    private static function push(array $userIds, array $payload, string $urgency, int $delay = 0): void
    {
        // Automatismes et commandes : boite d'envoi seulement, partie en un seul passage (flushOutbox). Avec
        // la transaction en cours : aucun appel reseau pendant un verrou, aucun push pour une notification annulee.
        $outboxOnly = self::$outboxOnly || (app()->runningInConsole() && ! app()->runningUnitTests());
        $id = DB::table('push_outbox')->insertGetId([
            'user_ids' => json_encode(array_values($userIds)),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'urgency' => $urgency,
            'created_at' => now(),
            'available_at' => $outboxOnly ? now() : null,
        ]);
        if ($outboxOnly) {
            return;
        }
        $job = fn () => self::deliver($id);

        // Tests : envoi direct, apres la validation de la transaction en cours s'il y en a une ; en
        // requete web, apres la reponse (le fidele n'attend pas), avec un delai d'execution suffisant
        // pour une grande audience (au-dela, le cron reprend au curseur).
        if (app()->runningInConsole()) {
            DB::afterCommit($job);
        } else {
            defer(function () use ($job, $delay) {
                ignore_user_abort(true);
                @set_time_limit(300);
                if ($delay > 0) {
                    sleep($delay);
                }
                $job();
            });
        }
    }

    /**
     * Push seul (sans notification dans la cloche, sans limite ni preferences), envoye apres
     * $delay secondes : test de l'utilisateur, le temps de quitter l'application (l'iPhone
     * n'affiche pas la notification quand l'application est au premier plan).
     *
     * @param  array<int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public static function pushLater(array $userIds, array $payload, int $delay): void
    {
        self::push($userIds, $payload, 'high', $delay);
    }

    /**
     * Passage de la boite d'envoi (cron chaque minute, automatismes) : envoie par lots, dans un temps
     * limite, ce qui peut partir (les automatismes tout de suite, le reste apres une minute laissee a
     * l'envoi immediat ; 3 essais au plus), les urgents d'abord ; abandonne proprement au-dela et nettoie
     * les anciens envois. Retourne le nombre d'envois termines.
     */
    public static function flushOutbox(int $budgetSeconds = self::PUSH_BUDGET_SECONDS): int
    {
        $sent = self::drain(fn (int $limit) => self::reserve($limit), microtime(true) + $budgetSeconds);

        $abandoned = DB::table('push_outbox')->whereNull('sent_at')->where('attempts', '>=', 3)
            ->where('claimed_at', '<', now()->subMinutes(5));
        if ($count = $abandoned->count()) {
            Log::error('Push : envois abandonnes apres 3 essais', ['count' => $count]);
            $abandoned->update(['sent_at' => now()]);
        }
        DB::table('push_outbox')->where('created_at', '<', now()->subDays(7))->delete();
        Cache::forever('push-outbox:last-run', now()->toIso8601String());

        return $sent;
    }

    /**
     * Envoie un element de la boite d'envoi. Reservation atomique : un meme envoi ne part
     * jamais deux fois, meme si l'envoi immediat et le cron se croisent. Retourne false si
     * l'element etait deja envoye ou en cours d'envoi, ou s'il reste des destinataires (repris par le cron).
     */
    public static function deliver(int $id): bool
    {
        $token = bin2hex(random_bytes(16));
        $claimed = DB::table('push_outbox')->where('id', $id)->whereNull('sent_at')
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(5)))
            ->update(['claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'claim_token' => $token]);
        if (! $claimed) {
            return false;
        }

        $rows = DB::table('push_outbox')->where('id', $id)->where('claim_token', $token)->get()->all();

        return self::drain(function () use (&$rows) {
            $next = $rows;
            $rows = [];

            return $next;
        }, microtime(true) + self::PUSH_WEB_BUDGET_SECONDS) === 1;
    }

    /**
     * Reserve des envois en une seule ecriture : un autre passage ne peut plus les prendre.
     *
     * @return array<int, object>
     */
    private static function reserve(int $limit): array
    {
        $free = fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(5));
        $ids = DB::table('push_outbox')->whereNull('sent_at')->where('attempts', '<', 3)
            ->where(fn ($q) => $q->where('available_at', '<=', now())
                ->orWhere(fn ($o) => $o->whereNull('available_at')->where('created_at', '<=', now()->subMinute())))
            ->where($free)
            ->orderByRaw("case urgency when 'high' then 0 when 'normal' then 1 else 2 end")->orderBy('id')
            ->limit($limit)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $token = bin2hex(random_bytes(16));
        DB::table('push_outbox')->whereIn('id', $ids)->whereNull('sent_at')->where($free)
            ->update(['claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'claim_token' => $token]);

        return DB::table('push_outbox')->whereIn('id', $ids)->where('claim_token', $token)
            ->orderByRaw("case urgency when 'high' then 0 when 'normal' then 1 else 2 end")->orderBy('id')->get()->all();
    }

    /**
     * Envoie des elements reserves, par lots de destinataires, chaque lot dans un seul pool. Apres chaque
     * lot : curseur et heure de reservation ecrits (une diffusion longue n'est jamais reprise par un autre
     * passage, et un processus coupe reprend au lot interrompu). Temps ecoule : les elements non termines
     * sont liberes pour le passage suivant. Retourne le nombre d'elements termines.
     *
     * @param  callable(int): array<int, object>  $reserve
     */
    private static function drain(callable $reserve, float $deadline): int
    {
        $webPush = app(WebPush::class);
        $finished = 0;
        $queue = [];
        $reservable = true;
        try {
            while (microtime(true) < $deadline) {
                if ($reservable && count($queue) < self::PUSH_LOT_ROWS) {
                    $more = $reserve(self::PUSH_LOT_ROWS);
                    $reservable = $more !== [];
                    foreach ($more as $row) {
                        $queue[$row->id] = [
                            'id' => (int) $row->id,
                            'users' => json_decode((string) $row->user_ids, true) ?: [],
                            'payload' => json_decode((string) $row->payload, true) ?: [],
                            'urgency' => (string) $row->urgency,
                            'cursor' => (int) $row->cursor,
                            'stats' => array_merge(['sent' => 0, 'expired' => 0, 'failed' => 0], (int) $row->cursor > 0 ? (json_decode((string) $row->result, true) ?: []) : []),
                        ];
                    }
                }
                if ($queue === []) {
                    break;
                }
                $finished += self::sendLot($queue, $webPush);
            }
        } catch (\Throwable $e) {
            // Erreur generale (cle, chiffrement...) : journalisee ; le cron retentera (3 essais au plus).
            Log::error('Push : envoi impossible', ['outbox' => array_keys($queue), 'error' => $e->getMessage()]);
            Monitor::integration('webpush', 'envoi', false, 0);
            DB::table('push_outbox')->whereIn('id', array_keys($queue))->update(['result' => json_encode(['error' => mb_substr($e->getMessage(), 0, 300)])]);

            return $finished;
        }

        // Temps ecoule : les elements non termines repartiront au passage suivant, au curseur (cet essai ne compte pas).
        if ($queue !== []) {
            DB::table('push_outbox')->whereIn('id', array_keys($queue))->update([
                'claimed_at' => null, 'claim_token' => null, 'attempts' => DB::raw('case when attempts > 0 then attempts - 1 else 0 end'),
            ]);
        }

        return $finished;
    }

    /**
     * Un lot : les PUSH_LOT_USERS destinataires suivants, un seul pool, puis l'avancement de chaque element.
     * Retourne le nombre d'elements termines (retires de $queue).
     *
     * @param  array<int, array{id: int, users: array<int, int>, payload: array<string, mixed>, urgency: string, cursor: int, stats: array<string, int>}>  $queue
     */
    private static function sendLot(array &$queue, WebPush $webPush): int
    {
        $startedAt = microtime(true);
        $room = self::PUSH_LOT_USERS;
        $taken = [];
        foreach ($queue as $id => $state) {
            $slice = array_slice($state['users'], $state['cursor'], $room);
            $taken[$id] = $slice;
            $room -= count($slice);
            if ($room <= 0) {
                break;
            }
        }

        $devices = PushSubscription::whereIn('user_id', array_unique(array_merge(...array_values($taken))))->get()->groupBy('user_id');
        $items = [];
        $owners = [];
        foreach ($taken as $id => $users) {
            foreach ($users as $userId) {
                foreach ($devices->get($userId, []) as $sub) {
                    $items[] = ['sub' => $sub, 'payload' => $queue[$id]['payload'], 'urgency' => $queue[$id]['urgency']];
                    $owners[] = $id;
                }
            }
        }
        $outcome = $items ? $webPush->sendBatch($items) : ['results' => []];
        $lot = ['sent' => 0, 'expired' => 0, 'failed' => 0];
        foreach ($outcome['results'] as $i => $result) {
            $queue[$owners[$i]]['stats'][$result]++;
            $lot[$result]++;
        }

        // Une seule ecriture validee par lot ; les elements termines avec le meme resultat sont groupes.
        $finished = 0;
        DB::transaction(function () use (&$queue, $taken, &$finished) {
            $done = [];
            foreach ($taken as $id => $users) {
                $state = &$queue[$id];
                $state['cursor'] += count($users);
                if ($state['cursor'] >= count($state['users'])) {
                    $done[$state['cursor'].'|'.json_encode($state['stats'])][] = $id;
                } else {
                    DB::table('push_outbox')->where('id', $id)->update([
                        'cursor' => $state['cursor'], 'claimed_at' => now(), 'attempts' => 1, 'result' => json_encode($state['stats']),
                    ]);
                }
                unset($state);
            }
            foreach ($done as $group => $ids) {
                [$cursor, $result] = explode('|', $group, 2);
                DB::table('push_outbox')->whereIn('id', $ids)->update(['sent_at' => now(), 'cursor' => (int) $cursor, 'result' => $result, 'claim_token' => null]);
                foreach ($ids as $id) {
                    unset($queue[$id]);
                }
                $finished += count($ids);
            }
        });

        // Supervision : appareils atteints / en echec (les abonnements expires ne sont pas des pannes).
        if ($items) {
            Monitor::integrationCounts('webpush', 'envoi', $lot['sent'], $lot['failed'], (int) round((microtime(true) - $startedAt) * 1000));
        }

        return $finished;
    }
}
