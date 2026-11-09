<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Event;
use App\Models\Gem;
use App\Models\MonthlyResolution;
use App\Models\ScheduledNotification;
use App\Models\SpiritualHealthForm;
use App\Models\Tribe;
use App\Models\User;
use App\Models\VertumetreForm;
use App\Support\Audience;
use App\Support\Audit;
use App\Support\AutomationPauses;
use App\Support\French;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Centre de notifications (administration) : les rappels automatiques (actifs, desactives ou suspendus pour une
 * periode), les rappels des evenements (coupes, ou coupes pendant une periode), les relances lancees a la main,
 * et les notifications personnalisees, envoyees tout de suite ou programmees.
 */
class NotificationCenter
{
    /** Relances manuelles : les memes membres que les rappels automatiques, une fois par jour au plus. */
    public const LAUNCHERS = [
        'fiss' => ['label' => 'Fiche de santé spirituelle (FISS)', 'help' => 'Les membres actifs qui n’ont pas encore rempli leur fiche du mois.', 'module' => null],
        'vertumetre' => ['label' => 'Vertumètre', 'help' => 'Les membres actifs qui n’ont pas encore rempli leur Vertumètre du mois.', 'module' => 'module.vertumetre'],
        'resolutions' => ['label' => 'Résolutions du mois (RM)', 'help' => 'Les membres actifs qui n’ont pas encore pris leurs résolutions du mois.', 'module' => 'module.resolutions'],
    ];

    /**
     * Rappels automatiques, groupe par groupe (memes cles que les Reglages, plus la periode de suspension).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function automations(): array
    {
        $pauses = AutomationPauses::all();
        $out = [];
        foreach (PlatformSettings::GROUPS as $group => [$title, , $items]) {
            foreach ($items as $key => $def) {
                if (! isset($def['step']) || ($def['type'] ?? null) !== 'bool') {
                    continue;
                }
                $pause = $pauses[$def['step']] ?? null;
                $out[] = [
                    'key' => $key,
                    'step' => $def['step'],
                    'group' => $title,
                    'label' => $def['label'],
                    'help' => $def['help'] ?? null,
                    'enabled' => PlatformSettings::enabled($key),
                    'module_on' => ! isset($def['module']) || PlatformSettings::enabled($def['module']),
                    'paused_from' => $pause['paused_from'] ?? null,
                    'paused_until' => $pause['paused_until'] ?? null,
                    'paused_now' => AutomationPauses::pausedToday($def['step']),
                    'reason' => $pause['reason'] ?? null,
                ];
            }
        }

        return $out;
    }

    /** Rappel automatique active ou desactive (meme reglage que dans Reglages). */
    public static function setEnabled(string $key, bool $enabled, User $by): void
    {
        $def = PlatformSettings::definitions()[$key] ?? null;
        abort_unless($def && isset($def['step']) && ($def['type'] ?? null) === 'bool', 404, 'Rappel inconnu.');
        $before = PlatformSettings::enabled($key);
        if ($before === $enabled) {
            return;
        }
        PlatformSettings::update([$key => $enabled], $by);
        Audit::log('notifications.automation_toggled', null, null, [$key => $before], [$key => $enabled]);
    }

    /** Suspension d'un rappel automatique pour une periode (ou retrait de la suspension). */
    public static function setPause(string $key, ?string $from, ?string $until, ?string $reason, User $by): void
    {
        $def = PlatformSettings::definitions()[$key] ?? null;
        abort_unless($def && isset($def['step']), 404, 'Rappel inconnu.');
        $before = AutomationPauses::all()[$def['step']] ?? null;
        AutomationPauses::set($def['step'], $from, $until, $reason, $by->id);
        Audit::log('notifications.automation_paused', null, null, ['pause' => $before], ['pause' => $from ? compact('from', 'until', 'reason') : null], ['key' => $key]);
    }

    /**
     * Evenements qui envoient des rappels (cultes reguliers et evenements a venir), avec leur etat de coupure.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function events(): array
    {
        $now = now();
        $today = $now->toDateString();

        return Event::query()->where('is_personal', false)
            ->where(fn ($q) => $q->where('starts_at', '>=', $now->copy()->startOfDay())
                ->orWhere(fn ($r) => $r->where('recurrence', '!=', 'none')
                    ->where(fn ($u) => $u->whereNull('recurrence_until')->orWhere('recurrence_until', '>=', $today))))
            ->orderByDesc('remind_all')->orderBy('title')->limit(200)
            ->get(['id', 'title', 'category', 'starts_at', 'recurrence', 'recurrence_until', 'remind_all', 'muted', 'muted_from', 'muted_until'])
            ->map(fn (Event $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'category' => $e->category,
                'starts_at' => $e->starts_at?->toIso8601String(),
                'recurrence' => $e->recurrence,
                'recurrence_label' => Event::RECURRENCES[$e->recurrence] ?? null,
                'regular' => (bool) $e->remind_all,
                'muted' => (bool) $e->muted,
                'muted_from' => $e->muted_from?->toDateString(),
                'muted_until' => $e->muted_until?->toDateString(),
                'muted_today' => $e->isMutedOn($today),
            ])->values()->all();
    }

    /** Rappels d'un evenement : coupes sans fin, coupes pour une periode, ou rouverts. */
    public static function setEventMute(Event $event, bool $muted, ?string $from, ?string $until, User $by): void
    {
        abort_if($event->is_personal, 404);
        $before = $event->only(['muted', 'muted_from', 'muted_until']);
        $event->forceFill([
            'muted' => $muted,
            'muted_from' => $muted ? null : $from,
            'muted_until' => $muted ? null : $until,
        ])->save();
        Audit::log('event.muted', $event, null, [
            'muted' => (bool) $before['muted'], 'muted_from' => $before['muted_from']?->toDateString(), 'muted_until' => $before['muted_until']?->toDateString(),
        ], ['muted' => $muted, 'muted_from' => $muted ? null : $from, 'muted_until' => $muted ? null : $until]);
    }

    // ---------------------------------------------------------------- Relances manuelles

    /** @return array<int> membres actifs (profil termine) concernes par la relance */
    public static function launcherTargets(string $launcher): array
    {
        $period = now()->format('Y-m');
        $done = match ($launcher) {
            'fiss' => SpiritualHealthForm::where('period', $period)->select('user_id'),
            'vertumetre' => VertumetreForm::where('period', $period)->select('user_id'),
            'resolutions' => MonthlyResolution::where('period', $period)->select('user_id'),
            default => abort(404, 'Relance inconnue.'),
        };

        return User::whereHas('profile', fn ($p) => $p->where('is_completed', true))
            ->where(fn ($q) => ActivityService::whereEffective($q))
            ->whereNull('platform_manager_at')
            ->whereNotIn('id', $done)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, array<string, mixed>> relances disponibles, avec le nombre de membres concernes */
    public static function launchers(): array
    {
        $today = now()->toDateString();
        $out = [];
        foreach (self::LAUNCHERS as $key => $def) {
            if ($def['module'] && ! PlatformSettings::enabled($def['module'])) {
                continue;
            }
            $out[] = $def + [
                'key' => $key,
                'targets' => count(self::launcherTargets($key)),
                'sent_today' => DB::table('notification_dispatches')->where('key', "manual:{$key}:{$today}")->exists(),
            ];
        }

        return $out;
    }

    /** Relance lancee a la main : une fois par jour, aux membres qui n'ont pas encore fait le geste du mois. */
    public static function launch(string $launcher, User $by): int
    {
        $def = self::LAUNCHERS[$launcher] ?? abort(404, 'Relance inconnue.');
        abort_if($def['module'] && ! PlatformSettings::enabled($def['module']), 422, 'Ce module est désactivé.');
        $targets = self::launcherTargets($launcher);
        abort_if($targets === [], 422, 'Personne à relancer : tous les membres actifs l’ont déjà fait ce mois-ci.');
        abort_unless(Notifier::once("manual:{$launcher}:".now()->toDateString()), 409, 'Cette relance a déjà été envoyée aujourd’hui.');

        $month = French::de(now()->locale('fr')->isoFormat('MMMM'));
        [$type, $title, $body, $url] = match ($launcher) {
            'fiss' => ['fiss', "Votre fiche {$month} vous attend", 'Prenez quelques minutes pour remplir votre fiche de santé spirituelle.', '/ma-fiche'],
            'vertumetre' => ['fiss', "Votre Vertumètre {$month} vous attend", 'Prenez quelques minutes pour remplir votre Vertumètre.', '/vertumetre'],
            'resolutions' => ['resolution', "Vos résolutions {$month} vous attendent", 'Prenez un moment pour écrire vos résolutions du mois.', '/mes-resolutions'],
        };
        $sent = Notifier::send($targets, $type, $title, $body, $url, ['manual' => true]);
        Audit::log('notifications.launched', null, null, [], ['launcher' => $launcher, 'recipients' => $sent]);

        return $sent;
    }

    // ---------------------------------------------------------------- Notifications personnalisees

    /** @return array{church: bool, tribes: array, gems: array, departments: array} tous les groupes de l'eglise */
    public static function audienceOptions(): array
    {
        return [
            'church' => true,
            'tribes' => Tribe::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'gems' => Gem::orderBy('name')->get(['id', 'name', 'tribe_id'])->toArray(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray(),
        ];
    }

    /**
     * Destinataires valides (toute l'eglise, ou des tribus, GEMs et departements existants).
     *
     * @param  array<int, array{type?: string, id?: int|null}>  $input
     * @return array<int, array{type: string, id: int|null}>
     */
    public static function scopes(array $input): array
    {
        $scopes = [];
        foreach ($input as $item) {
            $type = $item['type'] ?? null;
            if ($type === 'church') {
                return [['type' => 'church', 'id' => null]];
            }
            $id = (int) ($item['id'] ?? 0);
            $exists = match ($type) {
                'tribe' => Tribe::whereKey($id)->exists(),
                'gem' => Gem::whereKey($id)->exists(),
                'department' => Department::whereKey($id)->exists(),
                default => false,
            };
            abort_unless($exists, 422, 'Destinataires invalides.');
            $scopes["{$type}:{$id}"] = ['type' => $type, 'id' => $id];
        }
        abort_if($scopes === [], 422, 'Choisissez à qui envoyer la notification.');

        return array_values($scopes);
    }

    /** @return array<int> destinataires (membres inscrits, sans l'auteur) */
    public static function recipients(array $scopes, ?int $authorId): array
    {
        return array_values(array_diff(Audience::userIds($scopes), array_filter([$authorId])));
    }

    /** Envoi d'une notification personnalisee (tout de suite, ou a l'heure programmee). */
    public static function deliver(ScheduledNotification $n): int
    {
        $claimed = ScheduledNotification::whereKey($n->id)->where('status', ScheduledNotification::SCHEDULED)
            ->update(['status' => ScheduledNotification::SENT, 'sent_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return 0; // deja partie (deux passages simultanes) ou annulee entre-temps
        }
        $recipients = self::recipients($n->scopes ?? [], $n->created_by);
        $sent = Notifier::send($recipients, 'announcement', $n->title, $n->body, $n->url ?: '/notifications',
            ['scheduled_notification_id' => $n->id], $n->urgency === 'high' ? 'high' : 'normal');
        $n->forceFill(['recipients_count' => $sent])->save();

        return $sent;
    }

    /** Automatismes : les notifications programmees dont l'heure est venue. */
    public static function sendDue(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;
        foreach (ScheduledNotification::where('status', ScheduledNotification::SCHEDULED)->where('send_at', '<=', $now)->orderBy('send_at')->limit(50)->get() as $n) {
            $sent += self::deliver($n);
        }

        return $sent;
    }

    /** @return array<string, mixed> */
    public static function present(ScheduledNotification $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title,
            'body' => $n->body,
            'scopes' => $n->scopes,
            'urgency' => $n->urgency,
            'send_at' => $n->send_at?->toIso8601String(),
            'status' => $n->status,
            'recipients_count' => $n->recipients_count,
            'sent_at' => $n->sent_at?->toIso8601String(),
            'author' => $n->creator?->profile?->full_name ?: ($n->creator?->isPlatformManager() ? 'Administration' : null),
        ];
    }
}
