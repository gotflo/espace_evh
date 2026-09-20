<?php

namespace App\Support;

/**
 * Indices de notation de la Fiche de Sante Spirituelle (FISS).
 * Repris tel quel du document officiel (encadre d'aide a la notation).
 */
class FissCatalog
{
    public const SANCTIFICATION = ['mal', 'moyen', 'bien'];

    public static function indices(): array
    {
        return [
            'meditation_priere' => [
                'title' => 'Méditation / Prière',
                'scale' => '/20',
                'levels' => [
                    ['range' => '18-20', 'label' => 'Sans cesse - Excellent'],
                    ['range' => '15-17', 'label' => '3 fois par jour - Très bon'],
                    ['range' => '12-14', 'label' => '2 fois par jour - Bon'],
                    ['range' => '10-11', 'label' => '1 fois par jour - Moyen'],
                    ['range' => '7-9', 'label' => '1 fois par semaine - Faible'],
                    ['range' => '4-6', 'label' => '1 fois toutes les 2 semaines - Insuffisant'],
                    ['range' => '1-3', 'label' => '1 fois par mois - Très insuffisant'],
                    ['range' => '0', 'label' => 'Pas du tout - Mal'],
                ],
            ],
            'jeune' => [
                'title' => 'Jeûne',
                'scale' => '/20',
                'levels' => [
                    ['range' => '17', 'label' => 'Au moins 2 fois par semaine - Très bien'],
                    ['range' => '14', 'label' => '1 fois par semaine - Bien'],
                    ['range' => '10', 'label' => '1 fois par mois - Moyen'],
                    ['range' => '7', 'label' => '1 fois tous les 2 mois - Faible'],
                    ['range' => '4', 'label' => '1 fois tous les 3 mois - Insuffisant'],
                    ['range' => '0', 'label' => 'Pas du tout - Mal'],
                ],
            ],
            'sanctification' => [
                'title' => 'Sanctification',
                'scale' => '',
                'levels' => [
                    ['range' => 'Mal', 'label' => 'Je suis dans le péché'],
                    ['range' => 'Moyen', 'label' => 'Je suis tombé mais je me suis relevé en renonçant au péché'],
                    ['range' => 'Bien', 'label' => 'Je ne vis pas dans le péché'],
                ],
            ],
            'situation_financiere' => [
                'title' => 'Situation financière',
                'scale' => '/20',
                'levels' => [
                    ['range' => '18-20', 'label' => 'Excellent'],
                    ['range' => '15-17', 'label' => 'Très bon'],
                    ['range' => '12-14', 'label' => 'Bon'],
                    ['range' => '10-11', 'label' => 'Moyen'],
                    ['range' => '6-9', 'label' => 'Instable'],
                    ['range' => '3-5', 'label' => 'Difficile'],
                    ['range' => '0-2', 'label' => 'Très difficile'],
                ],
            ],
            'situation_conjugale_familiale' => [
                'title' => 'Situation conjugale / familiale',
                'scale' => '/20',
                'levels' => [
                    ['range' => '18-20', 'label' => 'Épanouie'],
                    ['range' => '15-17', 'label' => 'Très bon'],
                    ['range' => '12-14', 'label' => 'Bon'],
                    ['range' => '10-11', 'label' => 'Stable'],
                    ['range' => '6-9', 'label' => 'Instable'],
                    ['range' => '3-5', 'label' => 'Difficile'],
                    ['range' => '0-2', 'label' => 'Très difficile'],
                ],
            ],
        ];
    }
}
