<?php

namespace App\Support;

/**
 * Messages de benediction envoyes automatiquement (anniversaires, mariages).
 * Le verset est choisi de facon stable (meme personne + meme annee = meme verset) :
 * un nouvel envoi eventuel ne change pas le message.
 */
class Blessings
{
    public const BIRTHDAY_VERSES = [
        ['L\'Éternel te bénira et te gardera ! L\'Éternel fera luire sa face sur toi et t\'accordera sa grâce !', 'Nombres 6.24-25'],
        ['Car je connais les projets que j\'ai formés sur vous, projets de paix et non de malheur, afin de vous donner un avenir et de l\'espérance.', 'Jérémie 29.11'],
        ['Que l\'Éternel exauce tes désirs et accomplisse tous tes projets !', 'Psaume 20.5'],
        ['Tu couronnes l\'année de tes biens, et tes pas versent l\'abondance.', 'Psaume 65.12'],
        ['Celui qui a commencé en vous cette bonne œuvre la rendra parfaite pour le jour de Jésus-Christ.', 'Philippiens 1.6'],
        ['Enseigne-nous à bien compter nos jours, afin que nous appliquions notre cœur à la sagesse.', 'Psaume 90.12'],
        ['Mon Dieu pourvoira à tous vos besoins selon sa richesse, avec gloire, en Jésus-Christ.', 'Philippiens 4.19'],
    ];

    public const WEDDING_VERSES = [
        ['Ainsi ils ne sont plus deux, mais ils sont une seule chair. Que l\'homme donc ne sépare pas ce que Dieu a joint.', 'Matthieu 19.6'],
        ['La corde à trois fils ne se rompt pas facilement.', 'Ecclésiaste 4.12'],
        ['Par-dessus toutes ces choses, revêtez-vous de la charité, qui est le lien de la perfection.', 'Colossiens 3.14'],
        ['L\'amour est patient, il est plein de bonté ; l\'amour ne périt jamais.', '1 Corinthiens 13.4, 8'],
    ];

    /** @return array{0: string, 1: string} [texte, reference] */
    public static function verse(array $verses, int $seed): array
    {
        return $verses[$seed % count($verses)];
    }

    public static function birthdayMessage(string $firstName, int $userId): string
    {
        [$text, $ref] = self::verse(self::BIRTHDAY_VERSES, $userId + (int) now()->format('Y'));

        return "Toute la famille Vases d'Honneur Chicoutimi se réjouit avec toi en ce jour"
            .($firstName ? ", {$firstName}" : '')." ! Que cette nouvelle année soit remplie de la grâce et de la paix de Dieu.\n« {$text} » ({$ref})";
    }

    public static function weddingMessage(string $names, int $seed): string
    {
        [$text, $ref] = self::verse(self::WEDDING_VERSES, $seed + (int) now()->format('Y'));

        return "Joyeux anniversaire de mariage, {$names} ! L'église rend grâce à Dieu pour votre union.\n« {$text} » ({$ref})";
    }
}
