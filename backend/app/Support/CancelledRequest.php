<?php

namespace App\Support;

use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Messenger;
use App\Services\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demande retiree par le membre lui-meme (changement de tribu, FISS, Vertumetre, inscription a un service) :
 * exactement les personnes qui en avaient ete prevenues recoivent un avis d'annulation, sur le meme canal
 * (notification pour la cloche ; courriel seulement pour celles qui avaient recu un courriel).
 */
class CancelledRequest
{
    /**
     * Personnes prevenues de cette demande : notifications portant son identifiant ($key => $id) depuis sa creation.
     *
     * @return array<int>
     */
    public static function notifiedAbout(string $key, int $id, Carbon $since, int $memberId): array
    {
        return DB::table('user_notifications')
            ->where('created_at', '>=', $since->copy()->subMinute())
            ->where('data->'.$key, $id)
            ->where('user_id', '!=', $memberId)
            ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Avis dans la cloche aux personnes prevenues, avec le meme identifiant de demande.
     *
     * @return array<int> personnes prevenues de l'annulation
     */
    public static function notify(string $key, int $id, Carbon $since, User $member, string $type, string $title, string $body, ?string $url = null): array
    {
        $ids = self::notifiedAbout($key, $id, $since, $member->id);
        Notifier::send($ids, $type, $title, $body, $url, [$key => $id]);

        return $ids;
    }

    /**
     * Courriel d'annulation a celles et ceux qui avaient recu un courriel pour cette demande (cles anti-doublon
     * commencant par l'un des $prefixes). Un seul avis par personne et par demande.
     *
     * @param  array<string>  $prefixes  ex. ["tribe-change-request:12:", "tribe-change-info:12:requested:"]
     */
    public static function mail(array $prefixes, string $dedupe, User $member, string $what, ?string $detail): int
    {
        $sent = OutboundMessage::where('channel', 'email')->where('status', '!=', 'failed')
            ->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    // Prefixes fixes (lettres, chiffres, tirets, deux-points) : aucun caractere special du LIKE.
                    $q->orWhere('dedupe_key', 'like', $prefix.'%');
                }
            })
            ->whereNotNull('user_id')->where('user_id', '!=', $member->id)
            ->get(['user_id', 'recipient'])->unique('user_id');
        if ($sent->isEmpty()) {
            return 0;
        }

        $name = $member->profile?->full_name ?: (string) $member->phone;
        $firstNames = DB::table('profiles')->whereIn('user_id', $sent->pluck('user_id'))->pluck('first_name', 'user_id');
        $queued = 0;
        foreach ($sent as $row) {
            $queued += Messenger::queueEmail($row->recipient, (int) $row->user_id, 'request_cancelled', "Demande annulée : {$name}", 'request-cancelled', [
                'firstName' => (string) ($firstNames[$row->user_id] ?? ''),
                'memberName' => $name,
                'what' => $what,
                'detail' => $detail,
            ], "{$dedupe}:{$row->user_id}") ? 1 : 0;
        }

        return $queued;
    }
}
