<?php

namespace App\Console\Commands;

use App\Models\OutboundMessage;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use App\Services\Messenger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Relance des courriels en echec definitif (ex. prepares avant que l'envoi de courriel soit configure).
 * Apercu par defaut ; --oui les remet en file : ils partent au passage suivant de app:send-outbox.
 * Jamais relance : un echec trop ancien, un membre supprime ou bloque, une adresse retiree ou des courriels
 * coupes dans ses preferences, un rappel FISS d'un autre mois ou dont la fiche du mois est deja remplie.
 * L'adresse utilisee est celle du profil aujourd'hui (elle a pu changer depuis).
 */
class OutboxRetry extends Command
{
    protected $signature = 'app:outbox-retry
        {--kind= : seulement ce type de message (ex. fiss_reminder)}
        {--jours=14 : ignorer les echecs plus anciens que ce nombre de jours}
        {--oui : remettre vraiment en file (sinon simple apercu)}';

    protected $description = 'Relance les courriels en échec définitif (aperçu, puis --oui pour les remettre en file).';

    public function handle(): int
    {
        if (! Messenger::emailDeliverable()) {
            $this->error('Aucun envoi de courriel configuré sur ce serveur (MAIL_MAILER) : une relance échouerait à nouveau.');

            return self::FAILURE;
        }
        $days = max(1, (int) $this->option('jours'));
        $messages = OutboundMessage::where('channel', 'email')->where('status', 'failed')
            ->when($this->option('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderBy('id')->get();
        if ($messages->isEmpty()) {
            $this->info('Aucun courriel en échec.');

            return self::SUCCESS;
        }
        $users = User::with('profile')->whereIn('id', $messages->pluck('user_id')->filter()->unique())->get()->keyBy('id');

        $retry = [];
        $rows = [];
        foreach ($messages as $message) {
            [$reason, $to] = $this->check($message, $message->user_id ? $users->get($message->user_id) : null, $days);
            $rows[] = [$message->id, $message->kind, $message->created_at?->format('Y-m-d H:i'), self::mask($to ?? $message->recipient), $reason ?? 'à relancer'];
            if ($reason === null) {
                $retry[$message->id] = $to;
            }
        }
        $this->table(['#', 'Type', 'Préparé le', 'Destinataire', 'Décision'], $rows);
        $this->line(count($retry).' courriel(s) à relancer sur '.count($rows).'.');
        if (! $this->option('oui')) {
            $this->line('Aperçu seulement : rien n\'est envoyé. Relancez avec --oui pour les remettre en file.');

            return self::SUCCESS;
        }

        $queued = 0;
        foreach ($retry as $id => $to) {
            $queued += OutboundMessage::where('id', $id)->where('status', 'failed')->update([
                'status' => 'pending', 'recipient' => $to, 'attempts' => 0, 'available_at' => now(), 'last_error' => null, 'updated_at' => now(),
            ]);
        }
        Log::info('Courriels en échec remis en file', ['count' => $queued, 'ids' => array_keys($retry)]);
        $this->info($queued.' courriel(s) remis en file : ils partent dans la minute (app:send-outbox).');

        return self::SUCCESS;
    }

    /**
     * Faut-il relancer ce courriel ? [raison de ne pas le faire (null = a relancer), adresse a utiliser].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function check(OutboundMessage $message, ?User $user, int $days): array
    {
        if (! $message->created_at || $message->created_at->lt(now()->subDays($days))) {
            return ['trop ancien (plus de '.$days.' j)', null];
        }
        if ($message->user_id === null) {
            return [null, $message->recipient];
        }
        if (! $user || $user->blocked_at) {
            return ['compte supprimé ou bloqué', null];
        }
        $email = Messenger::emailOf($user);
        if (! $email) {
            return ['plus d\'adresse courriel', null];
        }
        if (! Messenger::wants($user, 'email', null)) {
            return ['courriels coupés dans ses préférences', null];
        }
        if ($message->kind === 'fiss_reminder' && preg_match('/^fiss:(\d{4}-\d{2}):/', (string) $message->dedupe_key, $m)) {
            if ($m[1] !== now()->format('Y-m')) {
                return ['rappel FISS d\'un autre mois', null];
            }
            if (SpiritualHealthForm::where('user_id', $user->id)->where('period', $m[1])->exists()) {
                return ['fiche du mois déjà remplie', null];
            }
        }

        return [null, $email];
    }

    /** « m***@gmail.com » : l'apercu ne recopie pas les adresses completes. */
    private static function mask(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return '—';
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).'***@'.$domain;
    }
}
