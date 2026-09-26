<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Models\UserNotification;
use App\Services\Notifier;
use App\Services\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class MyNotificationController extends Controller
{
    /** Services push des navigateurs (Chrome/Android, Firefox, Edge/Windows, Safari/iPhone). */
    private const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'push.apple.com',
    ];

    /** Centre de notifications : les plus recentes d'abord, pagination par curseur (before). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'before' => ['nullable', 'integer'],
            'unread' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $limit = (int) ($data['limit'] ?? 20);
        $user = $request->user();

        $query = $user->notifications()->orderByDesc('id');
        if (! empty($data['before'])) {
            $query->where('id', '<', $data['before']);
        }
        if (! empty($data['unread'])) {
            $query->whereNull('read_at');
        }
        $items = $query->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;

        return response()->json([
            'notifications' => $items->take($limit)->map(fn (UserNotification $n) => $this->present($n))->values(),
            'has_more' => $hasMore,
            'unread' => $user->notifications()->whereNull('read_at')->count(),
        ]);
    }

    /** Nombre de notifications non lues (badge de la cloche, icone de l'application). */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $request->user()->notifications()->whereNull('read_at')->count()]);
    }

    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        if (! $notification->read_at) {
            $notification->forceFill(['read_at' => now()])->save();
        }
        $this->syncAnnouncement($notification);

        return response()->json(['message' => 'ok']);
    }

    /** Tout marquer comme lu (annonces comprises). */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->notifications()->whereNull('read_at')->update(['read_at' => now()]);
        DB::table('announcement_user')->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    /**
     * Lecture automatique : ouvrir la page vers laquelle pointe une notification la marque
     * comme lue (inutile de vider la cloche a la main). Le tableau de bord est exclu :
     * il regroupe trop de choses pour considerer ses notifications comme vues.
     */
    public function markReadByUrl(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:255', 'starts_with:/']]);
        $url = $data['url'];
        $path = strtok($url, '?#') ?: $url;
        if ($path === '/' || $path === '/tableau-de-bord') {
            return response()->json(['count' => 0]);
        }

        // Meme page, et les parametres de la notification (ex. ?date=...) sont presents dans l'adresse visitee.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $visited);
        $ids = $request->user()->notifications()->whereNull('read_at')
            ->where(function ($q) use ($path) {
                $q->where('url', $path);
                \App\Support\Like::where($q, 'url', \App\Support\Like::escape($path).'?%', 'or');
                \App\Support\Like::where($q, 'url', \App\Support\Like::escape($path).'#%', 'or');
            })
            ->get(['id', 'url'])
            ->filter(function ($n) use ($visited) {
                parse_str((string) parse_url($n->url, PHP_URL_QUERY), $wanted);

                return array_intersect_assoc($wanted, $visited) == $wanted;
            })->pluck('id');

        $count = $ids->isEmpty() ? 0 : UserNotification::whereIn('id', $ids)->update(['read_at' => now()]);

        return response()->json(['count' => $count]);
    }

    public function destroy(Request $request, UserNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->delete();

        return response()->json(['message' => 'Notification retirée.']);
    }

    /** Supprime les notifications deja lues. */
    public function clearRead(Request $request): JsonResponse
    {
        $request->user()->notifications()->whereNotNull('read_at')->delete();

        return response()->json(['message' => 'Notifications lues retirées.']);
    }

    // ---------------------------------------------------------------- Push

    /** Cle publique VAPID + etat de l'abonnement pour cet appareil. */
    public function pushConfig(Request $request, WebPush $webPush): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) config('services.webpush.enabled'),
            'public_key' => config('services.webpush.enabled') ? $webPush->publicKey() : null,
            'devices' => $request->user()->pushSubscriptions()->count(),
        ]);
    }

    /** Enregistre (ou met a jour) l'abonnement push de l'appareil courant. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
            'content_encoding' => ['nullable', 'string', 'max:20'],
        ]);

        // Uniquement les services push des navigateurs (evite d'envoyer des requetes ailleurs).
        $host = strtolower((string) parse_url($data['endpoint'], PHP_URL_HOST));
        $allowed = collect(self::PUSH_HOSTS)->contains(fn ($h) => $host === $h || str_ends_with($host, '.'.$h));
        abort_unless($allowed, 422, 'Service de notification non reconnu.');

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => 'aes128gcm',
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 250),
            ],
        );

        return response()->json(['message' => 'Notifications activées sur cet appareil.']);
    }

    /** Desabonne l'appareil courant (ou tous les appareils si aucun endpoint n'est fourni). */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['nullable', 'string', 'max:2000']]);
        $query = $request->user()->pushSubscriptions();
        if (! empty($data['endpoint'])) {
            $query->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']));
        }
        $query->delete();

        return response()->json(['message' => 'Notifications désactivées.']);
    }

    /** Envoie une notification de test a l'utilisateur. */
    public function test(Request $request): JsonResponse
    {
        // Limite propre a ce bouton (5 par minute), independante de la limite generale de l'API.
        $key = 'push-test:'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Patientez une minute avant un nouvel essai.');
        RateLimiter::hit($key, 60);

        Notifier::send([$request->user()->id], 'system', 'Notifications activées ✅',
            'Vous recevrez ici les annonces, rappels et tâches de Vases d\'Honneur.', '/notifications');

        return response()->json(['message' => 'Notification de test envoyée.']);
    }

    /** @return array<string, mixed> */
    private function present(UserNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'type_label' => Notifier::TYPES[$n->type] ?? 'Information',
            'title' => $n->title,
            'body' => $n->body,
            'url' => $n->url,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    /** Lire la notification d'une annonce marque aussi l'annonce comme lue. */
    private function syncAnnouncement(UserNotification $n): void
    {
        $announcementId = $n->data['announcement_id'] ?? null;
        if ($n->type === 'announcement' && $announcementId) {
            DB::table('announcement_user')->where('user_id', $n->user_id)
                ->where('announcement_id', $announcementId)->whereNull('read_at')->update(['read_at' => now()]);
        }
    }
}
