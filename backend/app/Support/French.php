<?php

namespace App\Support;

/** Petites regles d'ecriture du francais pour les messages de l'application. */
class French
{
    /** « de septembre 2026 », mais « d'octobre 2026 », « d'avril », « d'août » (elision devant une voyelle). */
    public static function de(string $words): string
    {
        return preg_match('/^[aeiouyàâäéèêëîïôöûüh]/iu', $words) ? 'd’'.$words : 'de '.$words;
    }
}
