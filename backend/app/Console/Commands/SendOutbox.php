<?php

namespace App\Console\Commands;

use App\Services\AppointmentService;
use App\Services\Messenger;
use Illuminate\Console\Command;

/**
 * Chaque minute : prepare le courriel du jour des pasteurs (rendez-vous) puis envoie les courriels et
 * textos en file (limite par minute de l'hebergeur, reprise en cas d'echec, jamais en double).
 */
class SendOutbox extends Command
{
    protected $signature = 'app:send-outbox';

    protected $description = 'Envoie les courriels et textos en attente (et le courriel du jour des pasteurs).';

    public function handle(): int
    {
        try {
            AppointmentService::mailDigests();
        } catch (\Throwable $e) {
            report($e); // un probleme de calcul ne bloque pas les autres envois
        }
        $r = Messenger::flush();
        if ($r['sent'] || $r['failed']) {
            $this->info("Envoyés : {$r['sent']} · en échec définitif : {$r['failed']}");
        }

        return self::SUCCESS;
    }
}
