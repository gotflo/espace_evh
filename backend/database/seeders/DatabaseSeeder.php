<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Tribe;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Les 12 tribus (exemples, modifiables depuis l'administration plus tard).
        $tribes = ['Ruben', 'Simeon', 'Levi', 'Juda', 'Dan', 'Nephtali',
            'Gad', 'Aser', 'Issacar', 'Zabulon', 'Joseph', 'Benjamin'];
        foreach ($tribes as $name) {
            Tribe::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }

        // Departements de service (exemples).
        $departments = ['Louange', 'Accueil', 'Integration', 'Intercession', 'Media', 'Enfants',
            'Protocole', 'Sonorisation', 'Nettoyage', 'Evangelisation'];
        foreach ($departments as $name) {
            Department::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
    }
}
