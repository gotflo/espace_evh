<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Gem;
use App\Models\Tribe;

/**
 * Noms des tribus, GEMs et departements, charges une seule fois par requete (petites tables).
 * Evite 3 requetes par publication lors de l'affichage des listes (annonces, exercices...).
 */
class ScopeNames
{
    /** @return array<int, string> id => nom */
    public static function for(string $type): array
    {
        return once(fn () => match ($type) {
            'tribe' => Tribe::pluck('name', 'id')->all(),
            'gem' => Gem::pluck('name', 'id')->all(),
            'department' => Department::pluck('name', 'id')->all(),
            default => [],
        });
    }
}
