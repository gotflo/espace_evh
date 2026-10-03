<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Questionnaire des rapports mensuels (patriarche : tribu ; responsable de departement).
 * Chaque point du rapport demande par les pasteurs est decoupe en questions courtes pour aider
 * au remplissage. L'application affiche le questionnaire a partir de cette definition et le
 * serveur valide les reponses avec la meme : une seule source.
 *
 * Types de question :
 * - text   : texte libre ;
 * - choice : un seul choix parmi « options » ;
 * - number : nombre entier (min / max) ;
 * - checks : plusieurs choix, chacun avec une precision (reponse : cle du choix => precision) ;
 * - names  : liste de noms saisis a la main.
 * « show_if » : la question n'est posee (et exigee) que si une autre reponse remplit la condition
 * (in : valeur parmi la liste ; min / max : nombre).
 */
class LeaderReportCatalog
{
    public const TEXT_MAX = 3000;

    public const DETAIL_MAX = 1000;

    public const NAMES_MAX = 50;

    private const YES_NO = [['key' => 'oui', 'label' => 'Oui'], ['key' => 'non', 'label' => 'Non']];

    private const LEVELS = [
        ['key' => 'tres_bon', 'label' => 'Très bon'],
        ['key' => 'bon', 'label' => 'Bon'],
        ['key' => 'moyen', 'label' => 'Moyen'],
        ['key' => 'preoccupant', 'label' => 'Préoccupant'],
    ];

    /** @return array<int, array<string, mixed>> etapes du questionnaire pour ce type de rapport */
    public static function steps(string $kind): array
    {
        $tribe = $kind === 'tribe';
        $group = $tribe ? 'la tribu' : 'le département';
        $ofGroup = $tribe ? 'de la tribu' : 'du département';

        $steps = [
            [
                'key' => 'rencontres',
                'title' => "Rencontres d'échanges",
                'intro' => 'Thème partagé, dynamique du groupe, cas d\'incompréhension ou de recadrement.',
                'questions' => [
                    ['key' => 'rencontres_nombre', 'type' => 'number', 'min' => 0, 'max' => 31, 'required' => true,
                        'label' => "Combien de rencontres d'échanges ont eu lieu ce mois-ci ?"],
                    ['key' => 'rencontres_motif', 'type' => 'text', 'required' => true,
                        'label' => "Pourquoi aucune rencontre n'a-t-elle eu lieu ?",
                        'show_if' => ['key' => 'rencontres_nombre', 'max' => 0]],
                    ['key' => 'themes', 'type' => 'text', 'required' => true,
                        'label' => 'Quel(s) thème(s) ont été partagés ?',
                        'placeholder' => 'Ex. La persévérance dans la prière (Luc 18)',
                        'show_if' => ['key' => 'rencontres_nombre', 'min' => 1]],
                    ['key' => 'dynamique', 'type' => 'choice', 'required' => true,
                        'label' => 'Comment était la dynamique du groupe ?',
                        'options' => [
                            ['key' => 'tres_bonne', 'label' => 'Très bonne'],
                            ['key' => 'bonne', 'label' => 'Bonne'],
                            ['key' => 'moyenne', 'label' => 'Moyenne'],
                            ['key' => 'faible', 'label' => 'Faible'],
                        ],
                        'show_if' => ['key' => 'rencontres_nombre', 'min' => 1]],
                    ['key' => 'dynamique_detail', 'type' => 'text', 'required' => false,
                        'label' => 'Précisions sur la dynamique (participation, ambiance, assiduité)',
                        'show_if' => ['key' => 'rencontres_nombre', 'min' => 1]],
                    ['key' => 'recadrement', 'type' => 'choice', 'required' => true, 'options' => self::YES_NO,
                        'label' => "Y a-t-il eu des cas d'incompréhension ou de recadrement ?"],
                    ['key' => 'recadrement_detail', 'type' => 'text', 'required' => true,
                        'label' => "Que s'est-il passé et comment cela a-t-il été traité ?",
                        'show_if' => ['key' => 'recadrement', 'in' => ['oui']]],
                ],
            ],
            [
                'key' => 'activites',
                'title' => 'Activités menées',
                'intro' => 'Cochez ce qui a été fait ce mois-ci et résumez chaque activité en une ou deux phrases.',
                'questions' => [
                    ['key' => 'activites', 'type' => 'checks', 'required' => true,
                        'label' => 'Quelles activités ont été menées ?',
                        'options' => [
                            ['key' => 'evangelisation', 'label' => 'Évangélisation', 'placeholder' => 'Sorties, lieux, personnes rencontrées'],
                            ['key' => 'visites', 'label' => 'Visites', 'placeholder' => 'Qui a été visité, par qui'],
                            ['key' => 'appels', 'label' => 'Appels', 'placeholder' => 'Membres appelés, suivi effectué'],
                            ['key' => 'jeune', 'label' => 'Jeûne', 'placeholder' => 'Dates, sujets de prière'],
                            ['key' => 'drachme', 'label' => 'Drachme perdue retrouvée', 'placeholder' => 'Nom de la personne et circonstances'],
                            ['key' => 'fils_prodigue', 'label' => 'Fils prodigue ramené', 'placeholder' => 'Nom de la personne et circonstances'],
                            ['key' => 'brebis', 'label' => 'Brebis égarée retrouvée', 'placeholder' => 'Nom de la personne et circonstances'],
                            ['key' => 'autres', 'label' => 'Autres activités', 'placeholder' => 'Décrivez ces activités'],
                            ['key' => 'aucune', 'label' => 'Aucune activité ce mois-ci', 'placeholder' => 'Pour quelle raison ?', 'exclusive' => true],
                        ]],
                ],
            ],
        ];

        if ($tribe) {
            $steps[] = [
                'key' => 'gems',
                'title' => 'Fonctionnement des GEMs',
                'context' => 'gems',
                'questions' => [
                    ['key' => 'gems_fonctionnement', 'type' => 'choice', 'required' => true,
                        'label' => 'Les GEMs ont-ils bien fonctionné ?',
                        'options' => [
                            ['key' => 'oui', 'label' => 'Oui, tous'],
                            ['key' => 'en_partie', 'label' => 'En partie'],
                            ['key' => 'non', 'label' => 'Non'],
                        ]],
                    ['key' => 'gems_difficultes', 'type' => 'text', 'required' => true,
                        'label' => 'Quels GEMs sont concernés et quelles difficultés ont-ils rencontrées ?',
                        'show_if' => ['key' => 'gems_fonctionnement', 'in' => ['en_partie', 'non']]],
                    ['key' => 'gems_commentaire', 'type' => 'text', 'required' => false,
                        'label' => 'Un commentaire sur les GEMs ?',
                        'show_if' => ['key' => 'gems_fonctionnement', 'in' => ['oui']]],
                ],
            ];
        }

        $steps[] = [
            'key' => 'sante',
            'title' => 'Santé spirituelle',
            'intro' => "Observations et appréciations sur l'état de santé spirituelle générale {$ofGroup} durant le mois.",
            'context' => 'indicators',
            'questions' => [
                ['key' => 'sante_responsable', 'type' => 'text', 'required' => true,
                    'label' => $tribe ? 'Votre propre état spirituel ce mois-ci (patriarche)' : "L'état spirituel des responsables ce mois-ci"],
                ['key' => 'sante_membres_niveau', 'type' => 'choice', 'required' => true, 'options' => self::LEVELS,
                    'label' => "Dans l'ensemble, l'état spirituel des membres {$ofGroup} est…"],
                ['key' => 'sante_membres', 'type' => 'text', 'required' => true,
                    'label' => 'Vos observations sur les membres',
                    'placeholder' => 'Points encourageants, sujets de préoccupation, membres à accompagner'],
            ],
        ];

        $steps[] = [
            'key' => 'projets',
            'title' => 'Le mois à venir',
            'questions' => [
                ['key' => 'projets', 'type' => 'text', 'required' => true,
                    'label' => 'Quelles activités, programmes spéciaux ou innovations sont planifiés pour le mois à venir ?'],
            ],
        ];

        $steps[] = [
            'key' => 'ames',
            'title' => 'Âmes gagnées et intégrées',
            'intro' => "Combien d'âmes {$group} a gagnées et intégrées durant le mois ? Les noms ci-dessous viennent des inscriptions du mois.",
            'context' => 'souls',
            'questions' => [
                ['key' => 'ames_autres', 'type' => 'names', 'required' => false,
                    'label' => "Autres personnes gagnées, pas encore inscrites dans l'application"],
                ['key' => 'ames_commentaire', 'type' => 'text', 'required' => false,
                    'label' => 'Un commentaire ?'],
            ],
        ];

        return $steps;
    }

    /**
     * Nettoie les reponses (cles connues, types, longueurs). Avec $strict (envoi du rapport),
     * toute question posee et obligatoire doit avoir une reponse.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public static function clean(string $kind, array $answers, bool $strict): array
    {
        $questions = collect(self::steps($kind))->flatMap(fn ($s) => $s['questions'])->all();
        $clean = [];
        foreach ($questions as $q) {
            $value = self::cleanValue($q, $answers[$q['key']] ?? null);
            if ($value !== null) {
                $clean[$q['key']] = $value;
            }
        }
        // Une question masquee par une autre reponse ne garde pas d'ancienne reponse.
        foreach ($questions as $q) {
            if (! self::visible($q, $clean)) {
                unset($clean[$q['key']]);
            }
        }

        if ($strict) {
            $errors = [];
            foreach ($questions as $q) {
                if (self::visible($q, $clean) && ($error = self::missing($q, $clean[$q['key']] ?? null))) {
                    $errors['answers.'.$q['key']] = $error;
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $clean;
    }

    /** @param array<string, mixed> $answers */
    public static function visible(array $question, array $answers): bool
    {
        $cond = $question['show_if'] ?? null;
        if (! $cond) {
            return true;
        }
        $value = $answers[$cond['key']] ?? null;
        if ($value === null) {
            return false;
        }
        if (isset($cond['in'])) {
            return in_array($value, $cond['in'], true);
        }

        return (! isset($cond['min']) || $value >= $cond['min']) && (! isset($cond['max']) || $value <= $cond['max']);
    }

    private static function cleanValue(array $q, mixed $raw): mixed
    {
        $text = fn (mixed $v, int $max) => is_string($v) || is_numeric($v) ? mb_substr(trim((string) $v), 0, $max) : '';

        switch ($q['type']) {
            case 'text':
                return $text($raw, self::TEXT_MAX) ?: null;
            case 'choice':
                return in_array($raw, array_column($q['options'], 'key'), true) ? $raw : null;
            case 'number':
                return is_numeric($raw) ? max($q['min'], min($q['max'], (int) $raw)) : null;
            case 'checks':
                if (! is_array($raw)) {
                    return null;
                }
                $out = [];
                foreach ($q['options'] as $option) {
                    if (array_key_exists($option['key'], $raw)) {
                        $out[$option['key']] = $text($raw[$option['key']], self::DETAIL_MAX);
                    }
                }
                // Un choix exclusif (« aucune activité ») ne se combine pas avec les autres.
                foreach ($q['options'] as $option) {
                    if (! empty($option['exclusive']) && isset($out[$option['key']]) && count($out) > 1) {
                        unset($out[$option['key']]);
                    }
                }

                return $out ?: null;
            case 'names':
                if (! is_array($raw)) {
                    return null;
                }
                $names = array_values(array_unique(array_filter(array_map(fn ($n) => $text($n, 80), $raw))));

                return array_slice($names, 0, self::NAMES_MAX) ?: null;
        }

        return null;
    }

    /** Message si la reponse obligatoire manque, sinon null. */
    private static function missing(array $q, mixed $value): ?string
    {
        if ($q['type'] === 'checks' && is_array($value)) {
            $labels = array_column($q['options'], 'label', 'key');
            foreach ($value as $key => $detail) {
                if ($detail === '') {
                    return 'Précisez en quelques mots : '.$labels[$key].'.';
                }
            }
        }
        if (empty($q['required']) || $value !== null) {
            return null;
        }

        return 'Réponse attendue : '.$q['label'];
    }
}
