<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ScheduledNotification;
use App\Services\NotificationCenter;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Centre de notifications (compte de gestion seulement, middleware platform.manager) : rappels automatiques (activer, suspendre pour une
 * periode), rappels des evenements, relances manuelles, notifications personnalisees (tout de suite ou programmees).
 */
class NotificationCenterController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'automations' => NotificationCenter::automations(),
            'events' => NotificationCenter::events(),
            'launchers' => NotificationCenter::launchers(),
            'scheduled' => ScheduledNotification::with('creator.profile')->orderByRaw("CASE status WHEN 'scheduled' THEN 0 ELSE 1 END")
                ->orderByDesc('send_at')->limit(40)->get()->map(fn ($n) => NotificationCenter::present($n))->values(),
            'audiences' => NotificationCenter::audienceOptions(),
        ]);
    }

    public function toggle(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        NotificationCenter::setEnabled($key, (bool) $data['enabled'], $request->user());

        return response()->json(['message' => $data['enabled'] ? 'Rappel activé.' : 'Rappel désactivé.', 'automations' => NotificationCenter::automations()]);
    }

    public function pause(Request $request, string $key): JsonResponse
    {
        $data = $this->period($request, true);
        NotificationCenter::setPause($key, $data['from'], $data['until'], $data['reason'], $request->user());

        return response()->json([
            'message' => $data['from'] ? 'Rappel suspendu pour la période choisie.' : 'Suspension retirée.',
            'automations' => NotificationCenter::automations(),
        ]);
    }

    public function muteEvent(Request $request, Event $event): JsonResponse
    {
        $request->validate(['muted' => ['required', 'boolean']]);
        $data = $this->period($request, false);
        NotificationCenter::setEventMute($event, $request->boolean('muted'), $data['from'], $data['until'], $request->user());

        $message = match (true) {
            $request->boolean('muted') => 'Rappels de cet événement coupés.',
            $data['from'] !== null => 'Rappels coupés pour la période choisie : ils reprendront d’eux-mêmes.',
            default => 'Rappels de cet événement rétablis.',
        };

        return response()->json(['message' => $message, 'events' => NotificationCenter::events()]);
    }

    public function launch(Request $request, string $launcher): JsonResponse
    {
        $sent = NotificationCenter::launch($launcher, $request->user());

        return response()->json(['message' => "Relance envoyée à {$sent} membre(s).", 'launchers' => NotificationCenter::launchers()]);
    }

    /** Nombre de destinataires d'une selection (avant l'envoi). */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['scopes' => ['required', 'array', 'max:60']]);
        $scopes = NotificationCenter::scopes($request->input('scopes'));

        return response()->json(['recipients' => count(NotificationCenter::recipients($scopes, $request->user()->id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->message($request);
        $now = $data['send_at'] === null;
        $n = ScheduledNotification::create([
            'title' => $data['title'], 'body' => $data['body'], 'scopes' => $data['scopes'], 'urgency' => $data['urgency'],
            'send_at' => $data['send_at'] ?? now(), 'status' => ScheduledNotification::SCHEDULED, 'created_by' => $request->user()->id,
        ]);
        Audit::log($now ? 'notifications.sent' : 'notifications.scheduled', null, null, [], [
            'title' => $n->title, 'scopes' => $n->scopes, 'send_at' => $n->send_at->toIso8601String(),
        ], ['scheduled_notification_id' => $n->id]);

        if ($now) {
            $sent = NotificationCenter::deliver($n);

            return response()->json(['message' => "Notification envoyée à {$sent} personne(s).", 'notification' => NotificationCenter::present($n->fresh())], 201);
        }

        return response()->json([
            'message' => 'Notification programmée pour le '.$n->send_at->locale('fr')->isoFormat('D MMMM [à] H[h]mm').'.',
            'notification' => NotificationCenter::present($n),
        ], 201);
    }

    public function update(Request $request, ScheduledNotification $notification): JsonResponse
    {
        abort_unless($notification->status === ScheduledNotification::SCHEDULED, 409, 'Cette notification n’est plus programmée.');
        $data = $this->message($request);
        abort_if($data['send_at'] === null, 422, 'Choisissez la date et l’heure d’envoi.');
        $notification->update([
            'title' => $data['title'], 'body' => $data['body'], 'scopes' => $data['scopes'], 'urgency' => $data['urgency'], 'send_at' => $data['send_at'],
        ]);
        Audit::log('notifications.scheduled_updated', null, null, [], ['title' => $notification->title, 'send_at' => $notification->send_at->toIso8601String()],
            ['scheduled_notification_id' => $notification->id]);

        return response()->json(['message' => 'Notification programmée modifiée.', 'notification' => NotificationCenter::present($notification->fresh())]);
    }

    public function cancel(ScheduledNotification $notification): JsonResponse
    {
        $done = ScheduledNotification::whereKey($notification->id)->where('status', ScheduledNotification::SCHEDULED)
            ->update(['status' => ScheduledNotification::CANCELLED, 'updated_at' => now()]);
        abort_unless($done === 1, 409, 'Cette notification n’est plus programmée.');
        Audit::log('notifications.scheduled_cancelled', null, null, [], ['title' => $notification->title], ['scheduled_notification_id' => $notification->id]);

        return response()->json(['message' => 'Notification programmée annulée.']);
    }

    /** @return array{from: ?string, until: ?string, reason: ?string} periode (vide = pas de periode) */
    private function period(Request $request, bool $withReason): array
    {
        $data = $request->validate([
            'paused_from' => ['nullable', 'date_format:Y-m-d'],
            'paused_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:paused_from'],
            'reason' => ['nullable', 'string', 'max:160'],
        ], [
            'paused_until.after_or_equal' => 'La date de fin doit suivre la date de début.',
        ]);
        $from = $data['paused_from'] ?? null;
        $until = $data['paused_until'] ?? null;
        if (($from === null) !== ($until === null)) {
            abort(422, 'Indiquez la date de début et la date de fin.');
        }
        if ($from !== null) {
            abort_if(Carbon::parse($until)->lt(now()->startOfDay()), 422, 'Cette période est déjà passée.');
            abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($until)) > 366, 422, 'Une suspension dure un an au plus.');
        }

        return ['from' => $from, 'until' => $until, 'reason' => $withReason ? ($data['reason'] ?? null) : null];
    }

    /** @return array{title: string, body: ?string, urgency: string, scopes: array, send_at: ?Carbon} */
    private function message(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'urgency' => ['nullable', 'in:normal,high'],
            'scopes' => ['required', 'array', 'max:60'],
            'send_at' => ['nullable', 'date'],
        ]);
        $sendAt = isset($data['send_at']) ? Carbon::parse($data['send_at']) : null;
        if ($sendAt !== null) {
            abort_if($sendAt->lt(now()->subMinute()), 422, 'Cette date est déjà passée.');
            abort_if($sendAt->gt(now()->addYear()), 422, 'Programmez à moins d’un an.');
        }

        return [
            'title' => trim($data['title']),
            'body' => isset($data['body']) && trim($data['body']) !== '' ? trim($data['body']) : null,
            'urgency' => $data['urgency'] ?? 'normal',
            'scopes' => NotificationCenter::scopes($data['scopes']),
            'send_at' => $sendAt,
        ];
    }
}
