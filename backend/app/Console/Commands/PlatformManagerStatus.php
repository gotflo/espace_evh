<?php

namespace App\Console\Commands;

use App\Models\ManagerActivationCode;
use App\Models\ManagerDevice;
use App\Models\User;
use App\Support\PlatformManager;
use App\Support\SecurityJournal;
use Illuminate\Console\Command;

/**
 * Etat du compte de gestion principal, et retrait en cas de compromission :
 *   php artisan app:platform-manager            etat (numero masque, activation, sessions, codes en cours)
 *   php artisan app:platform-manager --revoke   retire le statut de gestion, ferme ses sessions et retire ses appareils
 */
class PlatformManagerStatus extends Command
{
    protected $signature = 'app:platform-manager {--revoke : retirer le statut de gestion et fermer les sessions}';

    protected $description = 'État du compte de gestion principal (ou retrait avec --revoke).';

    public function handle(): int
    {
        $phone = PlatformManager::phone();
        if (! $phone) {
            $this->error('Aucun numéro de gestion valide (PLATFORM_MANAGER_PHONE).');

            return self::FAILURE;
        }
        $user = User::where('phone', $phone)->first();
        $this->line('Numéro de gestion : …'.substr($phone, -4));
        $this->line('Compte : '.($user ? '#'.$user->id : 'pas encore créé'));
        $this->line('Activé : '.($user?->platform_manager_at ? 'oui, le '.$user->platform_manager_at->format('Y-m-d H:i') : 'non'));
        $this->line('Appareils de confiance : '.($user ? ManagerDevice::where('user_id', $user->id)->whereNull('revoked_at')->count() : 0));
        $this->line('Sessions ouvertes : '.($user ? $user->tokens()->count() : 0));
        $this->line('Code d\'activation en cours : '.(ManagerActivationCode::whereNull('used_at')->where('expires_at', '>', now())->exists() ? 'oui' : 'non'));

        if ($this->option('revoke')) {
            if (! $user?->platform_manager_at) {
                $this->warn('Le compte n\'est pas activé : rien à retirer.');

                return self::SUCCESS;
            }
            PlatformManager::revoke($user);
            SecurityJournal::record('manager.revoked', [], 'alert', null, $user);
            $this->info('Statut de gestion retiré, sessions fermées, appareils retirés. Une nouvelle activation demande un nouveau code.');
        }

        return self::SUCCESS;
    }
}
