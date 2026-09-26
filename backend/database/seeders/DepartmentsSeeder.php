<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Departements officiels (installation neuve). N'efface rien : un departement cree depuis
 * l'application est conserve. En production, les corrections passent par migration.
 */
class DepartmentsSeeder extends Seeder
{
    /** Nom => slug stable (le slug ne change pas quand l'orthographe du nom est corrigee). */
    public const DEPARTMENTS = [
        'Accueil' => 'accueil',
        'Baptême' => 'bapteme',
        'Chorale' => 'chorale',
        'Communication' => 'communication',
        'Ecodim 1' => 'ecodime',
        'Ecodim 2' => 'ecodim-2',
        'Eden 1' => 'eden-1',
        'Eden 2' => 'eden-2',
        'Évangélisation' => 'evangelisation',
        'Gestion de cultes' => 'gestion-des-cultes',
        'Intégration' => 'integration',
        'Intercession' => 'intercession',
        'Librairie' => 'librairie',
        'Protocole' => 'protocole',
        'Wedding Planner' => 'wedding-planner',
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $name => $slug) {
            Department::updateOrCreate(['slug' => $slug ?: Str::slug($name)], ['name' => $name, 'is_active' => true]);
        }
    }
}
