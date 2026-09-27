<?php

namespace App\Console\Commands;

use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rattrapage des notifications push (chaque minute, par le cron) : tout envoi reste en
 * attente plus d'une minute (processus interrompu apres la reponse, service push
 * injoignable...) est renvoye, 3 essais au plus. Nettoie aussi la boite d'envoi.
 */
class PushOutbox extends Command
{
    protected $signature = 'app:push-outbox';

    protected $description = 'Envoie les notifications push restees en attente et nettoie la boite d\'envoi.';

    public function handle(): int
    {
        $pending = DB::table('push_outbox')->whereNull('sent_at')->where('attempts', '<', 3)
            ->where('created_at', '<=', now()->subMinute())
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(5)))
            ->orderBy('id')->limit(50)->pluck('id');

        $sent = 0;
        foreach ($pending as $id) {
            $sent += Notifier::deliver((int) $id) ? 1 : 0;
        }
        if ($sent) {
            $this->info("{$sent} envoi(s) push rattrapé(s).");
        }

        // Abandon apres 3 essais : trace dans le journal (visible par l'hebergeur), puis nettoyage.
        $abandoned = DB::table('push_outbox')->whereNull('sent_at')->where('attempts', '>=', 3)
            ->where('claimed_at', '<', now()->subMinutes(5))->count();
        if ($abandoned) {
            Log::error('Push : envois abandonnes apres 3 essais', ['count' => $abandoned]);
            DB::table('push_outbox')->whereNull('sent_at')->where('attempts', '>=', 3)
                ->where('claimed_at', '<', now()->subMinutes(5))->update(['sent_at' => now()]);
        }
        DB::table('push_outbox')->where('created_at', '<', now()->subDays(7))->delete();

        return self::SUCCESS;
    }
}
