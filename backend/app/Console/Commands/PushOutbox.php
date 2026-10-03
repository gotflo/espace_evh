<?php

namespace App\Console\Commands;

use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * Rattrapage des notifications push (chaque minute, par le cron) : tout envoi reste en
 * attente plus d'une minute (processus interrompu apres la reponse, service push
 * injoignable...) est renvoye, 3 essais au plus. Nettoie aussi la boite d'envoi.
 * Les automatismes (app:tick) font le meme rattrapage, en secours.
 */
class PushOutbox extends Command
{
    protected $signature = 'app:push-outbox';

    protected $description = 'Envoie les notifications push restees en attente et nettoie la boite d\'envoi.';

    public function handle(): int
    {
        $started = microtime(true);
        try {
            $sent = Notifier::flushOutbox();
        } catch (\Throwable $e) {
            \App\Services\Monitoring\Monitor::jobRun('app:push-outbox', $started, 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }
        // Passages a vide (chaque minute) non enregistres : seulement ceux qui ont rattrape un envoi.
        if ($sent) {
            \App\Services\Monitoring\Monitor::jobRun('app:push-outbox', $started, 'ok', ['sent' => $sent]);
            $this->info("{$sent} envoi(s) push rattrapé(s).");
        }

        return self::SUCCESS;
    }
}
