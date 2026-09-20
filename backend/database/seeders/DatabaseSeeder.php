<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Donnees de reference (jamais les comptes utilisateurs) :
     * roles et permissions, tribus, departements.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            TribesSeeder::class,
            DepartmentsSeeder::class,
        ]);
    }
}
