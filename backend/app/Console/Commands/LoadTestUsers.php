<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\Role;
use App\Models\Tribe;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comptes temporaires pour un test de charge (scripts/load/k6-scenario.js).
 * Numeros reserves +1999555xxxx, jamais utilises par de vrais membres.
 * Refuse en production sauf --force (a lancer sur une copie de test de preference).
 *
 *   php artisan app:load-test-users 500          cree 500 comptes et leurs jetons
 *   php artisan app:load-test-users --delete     supprime tous les comptes de test
 */
class LoadTestUsers extends Command
{
    protected $signature = 'app:load-test-users {count=100} {--delete} {--force}';

    protected $description = 'Crée ou supprime des comptes temporaires pour un test de charge.';

    private const PREFIX = '+1999555';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusé en production. Utilisez une copie de test, ou --force en connaissance de cause.');

            return self::FAILURE;
        }

        if ($this->option('delete')) {
            $ids = User::where('phone', 'like', self::PREFIX.'%')->pluck('id');
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->delete();
            $deleted = User::whereIn('id', $ids)->delete();
            @unlink(storage_path('app/load-test-tokens.txt'));
            $this->info("{$deleted} compte(s) de test supprimé(s).");

            return self::SUCCESS;
        }

        $count = max(1, min(5000, (int) $this->argument('count')));
        $tribes = Tribe::pluck('id')->all();
        $fidele = Role::where('key', 'fidele')->value('id');
        $tokens = [];
        for ($i = 1; $i <= $count; $i++) {
            $user = User::firstOrCreate(['phone' => self::PREFIX.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
            Profile::firstOrCreate(['user_id' => $user->id], [
                'first_name' => 'Test', 'last_name' => 'Charge '.$i, 'is_completed' => true,
                'tribe_id' => $tribes ? $tribes[$i % count($tribes)] : null,
            ]);
            if ($fidele) {
                $user->roles()->syncWithoutDetaching([$fidele]);
            }
            $tokens[] = $user->createToken('test-de-charge')->plainTextToken;
        }
        file_put_contents(storage_path('app/load-test-tokens.txt'), implode("\n", $tokens)."\n");
        $this->info("{$count} compte(s) prêt(s). Jetons : storage/app/load-test-tokens.txt (à supprimer après le test avec --delete).");

        return self::SUCCESS;
    }
}
