<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;

class MakeSuperAdmin extends Command
{
    protected $signature = 'user:make-admin {phone : Numero de telephone du pasteur}';

    protected $description = 'Attribue le role super administrateur (pasteur) a un numero.';

    public function handle(): int
    {
        $phone = Phone::normalize($this->argument('phone'));
        if (! $phone) {
            $this->error('Numero invalide.');
            return self::FAILURE;
        }

        $user = User::firstOrCreate(['phone' => $phone]);
        Profile::firstOrCreate(['user_id' => $user->id]);

        $role = Role::where('key', User::SUPER_ADMIN)->first();
        if (! $role) {
            $this->error('Role super_admin introuvable. Lancez d\'abord les seeders.');
            return self::FAILURE;
        }

        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->info("Super administrateur attribue a {$phone}.");
        return self::SUCCESS;
    }
}
