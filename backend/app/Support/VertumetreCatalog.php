<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Vertumetre mensuel : questionnaire du membre, defini a un seul endroit (affiche par l'application,
 * valide par le serveur). Pour modifier une question ou un choix, c'est ce fichier.
 *
 * Types : single (choix unique, obligatoire), multi (choix multiples), text (texte long, facultatif).
 * « other » : champ texte qui accompagne un choix (ex. « Autre (precisez) »), demande quand ce choix
 * est coche ; « details » : champ texte qui suit la question (ex. « Expliquez »).
 */
class VertumetreCatalog
{
    private const PER_DAY = ['aucune' => 'Pas du tout ce mois-ci', '1x' => '1 fois par jour', '2x' => '2 fois par jour', '3x' => '3 fois ou plus par jour'];

    private const DURATION = ['0-15' => '0 à 15 min', '15-30' => '15 à 30 min', '30-60' => '30 min à 1 h', '60+' => '1 h et plus'];

    private const TOPICS = [
        'fruit_esprit' => 'Fruit de l’Esprit', 'caractere' => 'Caractère', 'mariage_foyer' => 'Mariage, foyer',
        'famille' => 'Famille', 'salut_ames' => 'Salut des âmes', 'professionnel' => 'Professionnel', 'sante' => 'Santé',
        'ministere' => 'Ministère', 'eglise_pasteurs' => 'Église, pasteurs',
    ];

    private const CHARACTER = [
        'na' => 'Cette situation ne s’est pas appliquée à moi ce mois-ci',
        'pas_du_tout' => 'Je n’y arrive pas du tout',
        'en_partie' => 'J’y arrive en partie (ou de temps en temps)',
        'pleinement' => 'J’y arrive pleinement (ou toujours)',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function sections(): array
    {
        return [
            [
                'key' => 'priere',
                'title' => 'Vie de prière et de jeûne',
                'intro' => null,
                'questions' => [
                    ['key' => 'priere_frequence', 'label' => 'Combien de fois priez-vous chaque jour ?', 'type' => 'single', 'options' => self::PER_DAY],
                    ['key' => 'priere_duree', 'label' => 'Combien de temps durent vos temps de prière ?', 'hint' => 'Durée moyenne de chaque temps de prière.', 'type' => 'single', 'options' => self::DURATION],
                    ['key' => 'jeune_fois', 'label' => 'Combien de fois avez-vous jeûné au cours de ce mois ?', 'type' => 'single',
                        'options' => ['aucune' => 'Pas du tout ce mois-ci', '1x' => '1 fois', '2x' => '2 fois', '3x' => '3 fois ou plus']],
                    ['key' => 'priere_sujets', 'label' => 'Sur quoi portent vos sujets de prière ?', 'type' => 'multi',
                        'options' => self::TOPICS + ['autre' => 'Autre'], 'other' => ['choice' => 'autre', 'key' => 'priere_sujets_autre', 'label' => 'Précisez']],
                    ['key' => 'soutien_priere', 'label' => 'Quel est le sujet pour lequel vous voulez qu’on vous soutienne en prière ?', 'type' => 'text'],
                ],
            ],
            [
                'key' => 'parole',
                'title' => 'Méditation de la Parole de Dieu et approfondissement spirituel',
                'intro' => null,
                'questions' => [
                    ['key' => 'meditation_frequence', 'label' => 'Combien de fois méditez-vous la Parole de Dieu chaque jour ?', 'type' => 'single', 'options' => self::PER_DAY],
                    ['key' => 'meditation_duree', 'label' => 'Combien de temps durent vos méditations de la Parole de Dieu ?', 'type' => 'single', 'options' => self::DURATION],
                    ['key' => 'lecture_volume', 'label' => 'Quel volume de lecture de la Bible avez-vous eu au cours de ce mois ?', 'type' => 'single',
                        'options' => ['aucune' => 'Pas du tout ce mois-ci', 'versets' => 'Quelques versets', 'chapitre' => 'Un chapitre entier', 'plus' => 'Plus d’un chapitre']],
                    ['key' => 'plan_lecture', 'label' => 'Suivez-vous un plan de lecture de la Bible ?', 'type' => 'single',
                        'options' => ['non' => 'Non', 'parfois' => 'De temps en temps', 'annee' => 'Sur toute l’année'],
                        'other' => ['choice' => ['parfois', 'annee'], 'key' => 'plan_lecture_precision', 'label' => 'Précisez le plan suivi', 'optional' => true]],
                    ['key' => 'enseignements', 'label' => 'Quels enseignements avez-vous écoutés au cours de ce mois ?', 'type' => 'text'],
                    ['key' => 'temoignage_sujets', 'label' => 'Témoignage portant sur :', 'type' => 'multi',
                        'options' => self::TOPICS + ['autres' => 'Autres'], 'details' => ['key' => 'temoignage_texte', 'label' => 'Expliquez']],
                ],
            ],
            [
                'key' => 'caractere',
                'title' => 'Fruit de l’Esprit et caractère',
                'intro' => 'Je m’examine à la lumière du Saint-Esprit. Ce mois-ci :',
                'questions' => [
                    ['key' => 'priorites', 'label' => 'Est-ce que j’ai réussi à toujours respecter les priorités que je me suis fixé(e) ?', 'type' => 'single', 'options' => self::CHARACTER],
                    ['key' => 'parole_tenue', 'label' => 'Est-ce que j’ai fait chaque fois ce que j’ai dit et est-ce que j’ai toujours dit ce que j’ai fait ?', 'type' => 'single', 'options' => self::CHARACTER],
                    ['key' => 'priere_avant', 'label' => 'Est-ce que j’ai prié d’abord avant de faire toute chose ?', 'type' => 'single', 'options' => self::CHARACTER],
                    ['key' => 'pardon', 'label' => 'Est-ce que j’ai vraiment pris le temps pour reconnaître mes erreurs et demander pardon ?', 'type' => 'single', 'options' => self::CHARACTER],
                    ['key' => 'irritation', 'label' => 'Est-ce que je me suis senti(e) énervé(e), irrité(e), vexé(e), frustré(e) ou humilié(e) par une situation ou une personne ?', 'type' => 'single',
                        'options' => ['na' => 'Cette situation ne s’est pas appliquée à moi ce mois-ci', 'tres_souvent' => 'Très souvent', 'parfois' => 'De temps en temps', 'pas_du_tout' => 'Pas du tout']],
                    ['key' => 'dieu_dabord', 'label' => 'En cas de besoin, même le plus simple, est-ce que j’ai d’abord demandé à Dieu, même si j’ai les ressources pour trouver une solution ?', 'type' => 'single', 'options' => self::CHARACTER],
                ],
            ],
        ];
    }

    /**
     * Pour l'application : choix en liste ordonnee [{value, label}] (l'ordre d'un objet JSON n'est pas garanti).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forClient(): array
    {
        return array_map(fn ($section) => ['questions' => array_map(fn ($q) => ['options' => isset($q['options'])
            ? array_map(fn ($v, $l) => ['value' => (string) $v, 'label' => $l], array_keys($q['options']), $q['options']) : [],
            // Espace insecable devant « ? » et « : » : le signe ne se retrouve jamais seul en debut de ligne.
            'label' => str_replace([' ?', ' :'], [' ?', ' :'], $q['label'])] + $q, $section['questions'])] + $section, self::sections());
    }

    /** @return array<string, array<string, mixed>> questions par cle */
    public static function questions(): array
    {
        $out = [];
        foreach (self::sections() as $section) {
            foreach ($section['questions'] as $q) {
                $out[$q['key']] = $q;
            }
        }

        return $out;
    }

    /**
     * Reponses validees et nettoyees : choix uniques obligatoires, choix multiples parmi les options,
     * textes facultatifs (3 000 caracteres), « Precisez » obligatoire quand « Autre » est coche.
     *
     * @return array<string, mixed>
     */
    public static function validate(array $answers): array
    {
        $clean = [];
        $errors = [];
        foreach (self::questions() as $key => $q) {
            $value = $answers[$key] ?? null;
            if ($q['type'] === 'single') {
                if (! is_string($value) || ! array_key_exists($value, $q['options'])) {
                    $errors["answers.$key"] = 'Choisissez une réponse.';

                    continue;
                }
                $clean[$key] = $value;
            } elseif ($q['type'] === 'multi') {
                $list = is_array($value) ? array_values(array_unique(array_filter($value, fn ($v) => is_string($v) && array_key_exists($v, $q['options'])))) : [];
                $clean[$key] = $list;
            } else {
                $clean[$key] = self::text($value);
            }

            foreach (['other', 'details'] as $extra) {
                if (! isset($q[$extra])) {
                    continue;
                }
                $field = $q[$extra];
                $text = self::text($answers[$field['key']] ?? null);
                $choices = (array) ($field['choice'] ?? []);
                $chosen = $extra === 'details' || array_intersect($choices, (array) ($clean[$key] ?? [])) !== [];
                if (! $chosen) {
                    $clean[$field['key']] = null;

                    continue;
                }
                if ($extra === 'other' && empty($field['optional']) && $text === null) {
                    $errors['answers.'.$field['key']] = 'Précisez votre réponse.';
                }
                $clean[$field['key']] = $text;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, 3000);
    }
}
