<?php

namespace App\Support;

/**
 * Mes RM (Résolutions du Mois) : definition unique des rubriques, des bilans et des themes
 * de ressources, affichee par l'application et validee par le serveur.
 */
class ResolutionCatalog
{
    public const VERSE = [
        'text' => 'À tes résolutions répondra le succès ; sur tes sentiers brillera la lumière.',
        'reference' => 'Job 22:28',
    ];

    /** Phrase qui reprend la resolution du mois precedent. */
    public const RENEW_PHRASE = 'Je renouvelle';

    public const OUTCOMES = [
        'atteinte' => 'Atteinte',
        'en_partie' => 'En partie',
        'non' => 'Pas encore',
    ];

    public const THEMES = [
        'meditation' => 'Méditation',
        'priere' => 'Prière',
        'jeune' => 'Jeûne',
        'culte' => 'Culte',
    ];

    /**
     * Rubriques (cle => libelle, question, exemple, rubrique FISS correspondante, theme de ressources).
     *
     * @return array<string, array{label: string, question: string, placeholder: string, fiss: ?string, theme: ?string}>
     */
    public static function fields(): array
    {
        return [
            'meditation' => [
                'label' => 'Méditation',
                'question' => 'Comment allez-vous méditer la Parole ce mois-ci ?',
                'placeholder' => 'Ex. lire un chapitre des Proverbes chaque matin',
                'fiss' => 'meditation', 'theme' => 'meditation',
            ],
            'priere' => [
                'label' => 'Prière',
                'question' => 'Quel temps de prière vous engagez-vous à vivre ?',
                'placeholder' => 'Ex. 30 minutes chaque soir avant de dormir',
                'fiss' => 'priere', 'theme' => 'priere',
            ],
            'jeune' => [
                'label' => 'Jeûne',
                'question' => 'Quel jeûne prévoyez-vous ?',
                'placeholder' => 'Ex. jeûner chaque mercredi jusqu\'à 18 h',
                'fiss' => 'jeune', 'theme' => 'jeune',
            ],
            'sanctification' => [
                'label' => 'Sanctification',
                'question' => 'Dans quel domaine voulez-vous marcher en sainteté ?',
                'placeholder' => 'Ex. maîtriser ma colère, garder mes yeux',
                'fiss' => 'sanctification', 'theme' => null,
            ],
            'assiduite' => [
                'label' => 'Assiduité aux cultes',
                'question' => 'Quel engagement prenez-vous pour les cultes ?',
                'placeholder' => 'Ex. être présent chaque dimanche à 8 h 45 et le mercredi',
                'fiss' => null, 'theme' => 'culte',
            ],
            'autres' => [
                'label' => 'Autres résolutions',
                'question' => 'Une autre résolution pour ce mois ?',
                'placeholder' => 'Famille, études, travail, service…',
                'fiss' => null, 'theme' => null,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::fields());
    }

    /** Le texte saisi est-il la phrase « Je renouvelle » (sans tenir compte des accents ni de la casse) ? */
    public static function isRenewPhrase(?string $text): bool
    {
        $norm = fn (string $s) => preg_replace('/[^a-z]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s));

        return $text !== null && $norm($text) === $norm(self::RENEW_PHRASE);
    }
}
