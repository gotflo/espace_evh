<?php

namespace App\Support;

/** Types d'evaluation notee /20 (inspire de PDVIE "Mes notes"). */
class EvaluationCatalog
{
    public const TYPES = [
        'devoir' => 'Devoir',
        'examen' => 'Examen',
        'interrogation' => 'Interrogation',
        'meditation_perso' => 'Méditation personnelle',
        'meditation_groupe' => 'Méditation de groupe',
        'discipline' => 'Discipline',
        'rapport_service' => 'Rapport de groupe de service',
        'autre' => 'Autre',
    ];

    public static function options(): array
    {
        return collect(self::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }
}
