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
        'priere' => 'Priere / accompagnement',
        'jeune' => 'Jeune',
        'visite' => 'Visite',
        'enseignement' => 'Enseignement recu',
        'exhortation' => 'Exhortation',
        'besoin' => 'Besoin / difficulte',
        'temoignage' => 'Temoignage',
        'autre' => 'Autre',
    ];

    /** Types que le fidele peut saisir lui-meme (self-service). */
    public const MEMBER_ENTRY_TYPES = ['temoignage', 'priere', 'besoin', 'jeune', 'autre'];

    /** Etapes du parcours (cle => libelle). */
    public const MILESTONES = [
        'nouveau_converti' => 'Nouveau converti',
        'bapteme_eau' => "Bapteme d'eau",
        'bapteme_esprit' => 'Bapteme du Saint-Esprit',
        'membre_engage' => 'Membre engage',
        'formation_disciple' => 'Formation de disciple',
        'service_actif' => 'Engage dans un service',
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
