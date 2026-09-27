<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Services\Push\WebPush;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Point d'entree unique des notifications : ecrit la notification dans le centre de
 * notifications de chaque destinataire puis l'envoie en push sur ses appareils abonnes.
 * Le push part apres la reponse HTTP (defer) pour ne pas ralentir l'application.
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
        'system' => 'Information',
    ];

    /**
     * Au-dela de ce nombre de notifications dans l'heure, plus de push (sauf urgence) : le reste
     * attend dans la cloche. Evite qu'une journee chargee fasse vibrer le telephone sans arret.
     */
    public const PUSH_HOURLY_LIMIT = 6;

    /**
     * Categories que chaque membre peut couper en push (la notification reste dans la cloche).
     * Les types absents de cette liste (FISS, reponses, famille, fonctions...) ne se coupent pas.
     */
    public const PREF_CATEGORIES = [
        'services' => 'Rappels des cultes',
        'events' => 'Événements et leurs rappels',
        'announcements' => 'Annonces',
        'exercises' => 'Exercices et leurs rappels',
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
            'task', 'task_reminder' => 'exercises',
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
                    ->groupBy('user_id')->havingRaw('count(*) > ?', [self::PUSH_HOURLY_LIMIT])->pluck('user_id')->all());
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
        try {
            DB::table('notification_dispatches')->insert(['key' => mb_substr($key, 0, 191), 'sent_at' => now()]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
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
        $id = DB::table('push_outbox')->insertGetId([
            'user_ids' => json_encode(array_values($userIds)),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'urgency' => $urgency,
            'created_at' => now(),
        ]);
        $job = fn () => self::deliver($id);

        // En console (cron, tests) on envoie directement, mais apres la validation de la
        // transaction en cours s'il y en a une (aucun appel reseau pendant un verrou, aucun push
        // pour une notification annulee) ; en requete web, apres la reponse (le fidele n'attend
        // pas), avec un delai d'execution suffisant pour une grande audience.
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
     * Envoie un element de la boite d'envoi. Reservation atomique : un meme envoi ne part
     * jamais deux fois, meme si l'envoi immediat et le cron se croisent. Retourne false si
     * l'element etait deja envoye ou en cours d'envoi.
     */
    public static function deliver(int $id): bool
    {
        $claimed = DB::table('push_outbox')->where('id', $id)->whereNull('sent_at')
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(5)))
            ->update(['claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
        if (! $claimed) {
            return false;
        }

        $row = DB::table('push_outbox')->find($id);
        $payload = json_decode((string) $row->payload, true) ?: [];
        $stats = ['sent' => 0, 'expired' => 0, 'failed' => 0];
        $webPush = app(WebPush::class);
        try {
            foreach (array_chunk(json_decode((string) $row->user_ids, true) ?: [], 500) as $ids) {
                PushSubscription::whereIn('user_id', $ids)->chunkById(200, function ($subs) use ($webPush, $payload, $row, &$stats) {
                    foreach ($webPush->sendMany($subs, $payload, 86400, (string) $row->urgency) as $k => $v) {
                        $stats[$k] = ($stats[$k] ?? 0) + $v;
                    }
                });
            }
        } catch (\Throwable $e) {
            // Erreur generale (cle, chiffrement...) : journalisee ; le cron retentera (3 essais au plus).
            Log::error('Push : envoi impossible', ['outbox' => $id, 'error' => $e->getMessage()]);
            DB::table('push_outbox')->where('id', $id)->update(['result' => json_encode(['error' => mb_substr($e->getMessage(), 0, 300)])]);

            return false;
        }

        DB::table('push_outbox')->where('id', $id)->update(['sent_at' => now(), 'result' => json_encode($stats)]);

        return true;
    }
}
