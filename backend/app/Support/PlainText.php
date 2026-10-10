<?php

namespace App\Support;

/**
 * Titres collés depuis YouTube ou les réseaux : lettres « stylisées » (𝗧𝘂 𝘃𝗲𝘂𝘅…), émojis
 * décoratifs, espaces multiples. Ramenés à un texte simple, lisible et identique sur tous
 * les téléphones (les lettres stylisées n'existent pas dans les polices de l'application).
 */
class PlainText
{
    private const DECORATIONS = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u';

    public static function title(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        // NFKC : 𝗧 -> T, et l'accent separe (a + ` ) redevient « à ».
        $plain = class_exists(\Normalizer::class) ? (\Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text) : $text;
        $plain = preg_replace(self::DECORATIONS, '', $plain) ?? $plain;

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    }
}
