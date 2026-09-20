<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DepartmentsSeeder extends Seeder
{
    /** Liste officielle des departements de l'eglise. */
    public const DEPARTMENTS = [
        'Accueil', 'Ecodime', 'Evangelisation', 'Integration', 'Intercession',
        'Chorale', 'Communication', 'Protocole', 'Gestion des cultes', 'Librairie', 'Bapteme',
    ];

    public function run(): void
    {
        $slugs = [];
        foreach (self::DEPARTMENTS as $name) {
            $slug = Str::slug($name);
            $slugs[] = $slug;
            Department::updateOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
        }

        // Aligne la liste : retire les departements qui ne sont plus dans la liste officielle.
        Department::whereNotIn('slug', $slugs)->delete();
    }
}
