<?php

namespace App\Support;

/**
 * Catalogue du suivi spirituel : types d'evenements du journal et etapes.
 * Centralise ici pour rester coherent et facilement modifiable.
 */
class SpiritualCatalog
{
    /** Types d'entree du journal (cle => libelle). */
    public const ENTRY_TYPES = [
        'conversion' => 'Conversion',
        'priere' => 'Prière / accompagnement',
        'jeune' => 'Jeûne',
        'visite' => 'Visite',
        'enseignement' => 'Enseignement reçu',
        'exhortation' => 'Exhortation',
        'besoin' => 'Besoin / difficulté',
        'temoignage' => 'Témoignage',
        'autre' => 'Autre',
    ];

    /** Types que le fidele peut saisir lui-meme (self-service). */
    public const MEMBER_ENTRY_TYPES = ['temoignage', 'priere', 'besoin', 'jeune', 'autre'];

    /** Etapes du parcours (cle => libelle). */
    public const MILESTONES = [
        'nouveau_converti' => 'Nouveau converti',
        'bapteme_eau' => "Baptême d'eau",
        'bapteme_esprit' => 'Baptême du Saint-Esprit',
        'membre_engage' => 'Membre engagé',
        'formation_disciple' => 'Formation de disciple',
        'service_actif' => 'Engagé dans un service',
    ];

    public static function entryTypes(): array
    {
        return collect(self::ENTRY_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }

    public static function milestones(): array
    {
        return collect(self::MILESTONES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }
}
