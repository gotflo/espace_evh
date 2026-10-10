<?php

namespace App\Console\Commands;

use App\Support\PlatformManager;
use App\Support\SecurityJournal;
use Illuminate\Console\Command;

/**
 * Code d'autorisation d'un appareil pour le compte de gestion principal (la premiere autorisation active le
 * compte), genere SUR LE SERVEUR par le proprietaire :
 *   php artisan app:manager-activation-code
 * Le code s'affiche une seule fois (seule son empreinte est conservee), sert une seule fois et
 * expire (24 h par defaut). Generer un nouveau code invalide le precedent.
 */
class ManagerActivationCode extends Command
{
    protected $signature = 'app:manager-activation-code';

    protected $description = 'Génère un code (usage unique) pour autoriser un appareil du compte de gestion principal.';

    public function handle(): int
    {
        $phone = PlatformManager::phone();
        if (! $phone) {
            $this->error('Aucun numéro de gestion valide (PLATFORM_MANAGER_PHONE).');

            return self::FAILURE;
        }

        $code = PlatformManager::issueActivationCode();
        $hours = max(1, (int) config('platform.manager.activation_code_ttl_hours', 24));
        SecurityJournal::record('manager.activation_code_issued', ['valid_hours' => $hours], 'warning');

        $this->line('Numéro de gestion : …'.substr($phone, -4));
        $this->info('Code d\'autorisation : '.$code);
        $this->line("Valable {$hours} h, une seule fois. Il ne sera plus affiché : transmettez-le au propriétaire du numéro.");
        $this->line('Dans l\'application, sur l\'appareil à autoriser : saisir le numéro (aucun SMS), puis ce code.');
        $this->line('Ensuite, sur cet appareil, le numéro suffit. Un autre appareil demande un nouveau code.');

        return self::SUCCESS;
    }
}
