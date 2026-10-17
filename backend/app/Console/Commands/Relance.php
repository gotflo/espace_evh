<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MemberMessages;
use App\Services\Messenger;
use App\Services\Notifier;
use App\Services\Sms\TwilioMessagingClient;
use App\Services\WelcomeBackService;
use App\Support\Audit;
use App\Support\PlatformSettings;
use App\Support\Recipients;
use App\Support\Registration;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Relance manuelle demandee par les pasteurs, en plus des rappels automatiques d'app:tick qui, eux,
 * s'arretent (courriel : une invitation puis un seul rappel ; absence : un message entre 7 et 30 jours).
 * Sans --envoyer : apercu (nombre de destinataires, canaux, texte exact), rien n'est envoye.
 * Chaque personne est relancee au plus une fois par jour et par cible (journal anti-doublon).
 * Tribu : liste seulement, le texto part a la main (textos de la plateforme non configures, et
 * l'application montre d'abord le choix de la tribu : une notification n'y serait pas lue).
 */
class Relance extends Command
{
    protected $signature = 'app:relance
        {cible : courriel | absents | tribu}
        {--jours= : absents : aucune visite depuis au moins N jours (par defaut le reglage « absent », 7 jours)}
        {--liste : affiche aussi chaque personne visee (nom, numero, derniere visite)}
        {--envoyer : envoie vraiment (sans cette option : apercu, rien n\'est envoye)}';

    protected $description = 'Relance les membres sans courriel ou absents (apercu par defaut) ; tribu : liste pour un texto envoye a la main.';

    public const EMAIL_TITLE = 'Ajoutez votre adresse courriel, c’est important';

    public const EMAIL_BODY = 'Votre devoir, vos rappels et les messages importants de l’église arriveront aussi dans votre boîte courriel. Ajoutez votre adresse en un instant.';

    /** Ouvre la saisie de l'adresse sur le tableau de bord, meme si elle avait ete remise a plus tard. */
    public const EMAIL_URL = '/tableau-de-bord?courriel=1';

    public function handle(): int
    {
        return match ($this->argument('cible')) {
            'courriel' => $this->email(),
            'absents' => $this->absents(),
            'tribu' => $this->tribe(),
            default => $this->refuse('Cible inconnue : courriel, absents ou tribu.'),
        };
    }

    // ---------------------------------------------------------------- Courriel manquant

    private function email(): int
    {
        $users = $this->members()
            ->whereHas('profile', fn ($p) => $p->where(fn ($q) => $q->whereNull('email')->orWhere('email', '')))
            ->with('profile')->withCount('pushSubscriptions')->orderBy('id')->get();
        $absentAfter = PlatformSettings::int('absences.after_days');

        $this->line('Relance « courriel » : membres inscrits sans adresse courriel.');
        $this->line('Destinataires : '.$users->count());
        $this->line('  - avec les notifications push sur un appareil : '.$users->where('push_subscriptions_count', '>', 0)->count());
        $this->line("  - absents depuis plus de {$absentAfter} jours (la verront à leur prochaine visite) : "
            .$users->filter(fn (User $u) => $this->away($u, $absentAfter))->count());
        $this->line('Notification (cloche et push, prioritaire) :');
        $this->line('  « '.self::EMAIL_TITLE.' »');
        $this->line('  '.self::EMAIL_BODY);
        $this->line('  Ouvre : '.self::EMAIL_URL.' (saisie de l’adresse en un geste)');
        $this->roster($users);
        if (! $this->option('envoyer')) {
            return $this->previewOnly('courriel');
        }

        $today = now()->toDateString();
        $sent = 0;
        foreach ($users->chunk(200) as $chunk) {
            DB::transaction(function () use ($chunk, $today, &$sent) {
                $ids = [];
                foreach ($chunk as $user) {
                    if (! Notifier::once("relance:courriel:{$user->id}:{$today}")) {
                        continue;
                    }
                    // Meme suivi que l'invitation automatique (app:tick email-invites) : la relance compte.
                    $prefs = is_array($user->notification_prefs) ? $user->notification_prefs : [];
                    $prefs['email_invites'] = array_merge(array_values((array) ($prefs['email_invites'] ?? [])), [$today]);
                    $user->forceFill(['notification_prefs' => $prefs])->save();
                    $ids[] = $user->id;
                }
                $sent += Notifier::send($ids, 'profile', self::EMAIL_TITLE, self::EMAIL_BODY, self::EMAIL_URL, [], 'high');
            });
        }
        if ($sent > 0) {
            Audit::log('members.relanced', null, null, [], ['target' => 'courriel', 'recipients' => $sent]);
        }
        $this->info("Relance envoyée : {$sent} membre(s).".($sent < $users->count() ? ' Les autres avaient déjà été relancés aujourd’hui.' : ''));

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- Absents

    private function absents(): int
    {
        $days = (int) ($this->option('jours') ?: PlatformSettings::int('absences.after_days'));
        if ($days < 1) {
            return $this->refuse('--jours doit être un nombre de jours (1 ou plus).');
        }
        // Memes regles que l'absence automatique : autorites pastorales exclues, compte valide depuis
        // plus d'une semaine.
        $users = $this->members()
            ->whereRaw('COALESCE(last_seen_at, last_login_at) < ?', [now()->subDays($days)->toDateTimeString()])
            ->where(fn ($q) => $q->whereNull('approval_decided_at')->orWhere('approval_decided_at', '<', now()->subDays(7)))
            ->whereNotIn('id', Recipients::withPermission('members.view_all') ?: [0])
            ->with('profile')->withCount('pushSubscriptions')->orderBy('id')->get();

        $channels = $users->countBy(fn (User $u) => $this->channel($u));
        $already = $users->filter(fn (User $u) => $u->absence_notified_at && $u->absence_notified_at->gte($this->lastSeen($u)))->count();
        $buckets = $users->countBy(fn (User $u) => match (true) {
            $this->daysAway($u) <= 13 => 'moins de 14 jours',
            $this->daysAway($u) <= 30 => 'de 14 à 30 jours',
            $this->daysAway($u) <= 60 => 'de 31 à 60 jours',
            default => 'plus de 60 jours',
        });

        $this->line("Relance « absents » : aucune visite depuis au moins {$days} jours (autorités pastorales exclues).");
        $this->line('Destinataires : '.$users->count());
        foreach (['moins de 14 jours', 'de 14 à 30 jours', 'de 31 à 60 jours', 'plus de 60 jours'] as $bucket) {
            if (isset($buckets[$bucket])) {
                $this->line("  - absents {$bucket} : {$buckets[$bucket]}");
            }
        }
        $this->line("  - déjà relancés automatiquement pour cette absence : {$already}");
        $this->line('Canal hors de l’application :');
        $this->line('  - courriel : '.($channels['courriel'] ?? 0).(Messenger::emailDeliverable() ? '' : ' (ATTENTION : aucun envoi de courriel configuré sur ce serveur)'));
        $this->line('  - texto : '.($channels['texto'] ?? 0).(TwilioMessagingClient::enabled() ? '' : ' (textos de notification non configurés)'));
        $this->line('  - push seulement : '.($channels['push'] ?? 0));
        $this->line('  - rien avant leur prochaine visite (ni courriel ni push) : '.($channels['aucun'] ?? 0));
        $this->line('Courriel : « Votre espace My vasesdhonneur vous attend »');
        $this->line('  « Vous nous manquez, <prénom> ! Vous ne vous êtes pas connecté(e) à My vasesdhonneur depuis <N> jours. »');
        $this->line('  + ce qui l’attend depuis sa dernière visite (devoirs, exercices, annonces, événements) + bouton « Ouvrir mon espace ».');
        $this->line('Notification (cloche et push) : « Votre espace vous attend »');
        $this->line('  Votre dernière visite remonte à <N> jours. Venez voir ce qui vous attend.');
        $this->roster($users, true);
        if (! $this->option('envoyer')) {
            return $this->previewOnly('absents'.($this->option('jours') ? " --jours={$days}" : ''));
        }

        $today = now()->toDateString();
        $sent = 0;
        $byChannel = [];
        foreach ($users->chunk(100) as $chunk) {
            DB::transaction(function () use ($chunk, $today, &$sent, &$byChannel) {
                foreach ($chunk as $user) {
                    if (! Notifier::once("relance:absents:{$user->id}:{$today}")) {
                        continue;
                    }
                    $seen = $this->lastSeen($user);
                    $days = $this->daysAway($user);
                    // Compte comme le message d'absence : l'automatisme ne renverra rien pour cette absence.
                    User::whereKey($user->id)->update(['absence_notified_at' => now()]);
                    $channel = MemberMessages::absence($user, $days, WelcomeBackService::for($user, $seen), "relance-absence:{$user->id}:{$today}");
                    $byChannel[$channel ?? 'push / application'] = ($byChannel[$channel ?? 'push / application'] ?? 0) + 1;
                    // Meme texte que l'absence automatique (app:tick absences).
                    $sent += Notifier::send([$user->id], 'system', 'Votre espace vous attend',
                        'Votre dernière visite remonte à '.$days.' jours. Venez voir ce qui vous attend.', '/tableau-de-bord');
                }
            });
        }
        if ($sent > 0) {
            Audit::log('members.relanced', null, null, [], ['target' => 'absents', 'days' => $days, 'recipients' => $sent, 'channels' => $byChannel]);
        }
        $this->info("Relance envoyée : {$sent} membre(s) (".collect($byChannel)->map(fn ($n, $c) => "{$c} : {$n}")->implode(', ').').'
            .($sent < $users->count() ? ' Les autres avaient déjà été relancés aujourd’hui.' : ''));

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- Tribu

    private function tribe(): int
    {
        // Membres deja inscrits rouverts quand la tribu est devenue obligatoire (2026-10-07) : a leur
        // prochaine ouverture, l'application leur demande seulement de choisir leur tribu.
        $users = User::exceptPlatformManagers()->where('approval_status', User::APPROVED)->whereNull('blocked_at')
            ->whereHas('profile', fn ($p) => $p->whereNull('tribe_id')->whereNotNull('matricule')->where('matricule', '!=', ''))
            ->whereDoesntHave('roles', fn ($r) => $r->whereIn('key', Registration::TRIBE_EXEMPT_ROLES))
            ->with('profile')->orderBy('id')->get();

        $this->line('Tribu : membres déjà inscrits qui n’ont pas encore choisi leur tribu.');
        $this->line('Personnes visées : '.$users->count().' (l’application leur demande la tribu dès qu’ils l’ouvrent).');
        $this->line('Envoi : texto à envoyer à la main (les textos de la plateforme ne sont pas configurés).');
        $this->roster($users);
        if ($this->option('envoyer')) {
            return $this->refuse('Rien à envoyer depuis la plateforme pour la tribu : utilisez la liste (--liste) pour le texto.');
        }

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- Outils

    /** Membres inscrits (profil complet, compte valide, non bloque), hors compte de gestion. */
    private function members(): Builder
    {
        return User::exceptPlatformManagers()->where('approval_status', User::APPROVED)->whereNull('blocked_at')
            ->whereHas('profile', fn ($p) => $p->where('is_completed', true));
    }

    private function lastSeen(User $user): ?Carbon
    {
        return collect([$user->last_seen_at, $user->last_login_at])->filter()->max();
    }

    private function daysAway(User $user): int
    {
        $seen = $this->lastSeen($user);

        return $seen ? (int) $seen->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0;
    }

    private function away(User $user, int $days): bool
    {
        $seen = $this->lastSeen($user);

        return $seen === null || $seen->lt(now()->subDays($days));
    }

    /** Canal qui portera le message d'absence hors de l'application (meme ordre que Messenger::toMember). */
    private function channel(User $user): string
    {
        if (Messenger::emailOf($user) && Messenger::wants($user, 'email', null)) {
            return 'courriel';
        }
        if ($user->phone && TwilioMessagingClient::enabled() && Messenger::wants($user, 'sms', null)) {
            return 'texto';
        }

        return $user->push_subscriptions_count > 0 ? 'push' : 'aucun';
    }

    /** --liste : une ligne par personne visee. */
    private function roster(Collection $users, bool $withChannel = false): void
    {
        if (! $this->option('liste') || $users->isEmpty()) {
            return;
        }
        $this->table(array_merge(['Nom', 'Numéro', 'Dernière visite'], $withChannel ? ['Canal'] : []),
            $users->map(fn (User $u) => array_merge([
                trim(($u->profile?->first_name ?? '').' '.($u->profile?->last_name ?? '')) ?: '—',
                $u->phone,
                $this->lastSeen($u)?->toDateString() ?? 'jamais',
            ], $withChannel ? [$this->channel($u)] : []))->all());
    }

    private function previewOnly(string $command): int
    {
        $this->newLine();
        $this->warn('APERÇU : rien n’a été envoyé. Pour envoyer : php artisan app:relance '.$command.' --envoyer');

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
