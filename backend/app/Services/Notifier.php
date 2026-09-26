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
        'system' => 'Information',
    ];

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

        if (config('services.webpush.enabled')) {
            self::push($ids->all(), [
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

    /** @param array<int> $userIds @param array<string, mixed> $payload */
    private static function push(array $userIds, array $payload, string $urgency): void
    {
        $job = function () use ($userIds, $payload, $urgency) {
            $webPush = app(WebPush::class);
            PushSubscription::whereIn('user_id', $userIds)->chunkById(100, function ($subs) use ($webPush, $payload, $urgency) {
                foreach ($subs as $sub) {
                    try {
                        $webPush->send($sub, $payload, 86400, $urgency);
                    } catch (\Throwable $e) {
                        Log::warning('Push : erreur', ['error' => $e->getMessage()]);
                    }
                }
            });
        };

        // En console (cron, tests) on envoie directement ; en requete web, apres la reponse.
        if (app()->runningInConsole()) {
            $job();
        } else {
            defer($job);
        }
    }
}
