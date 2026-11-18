<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Texte enrichi des devoirs (resume, actions et resolutions, commentaires du pasteur) : document de l'editeur
 * (format ProseMirror / TipTap, en JSON) RECONSTRUIT ici a partir d'une liste fermee de blocs, de styles et de
 * couleurs. Rien d'autre n'est garde : aucun HTML, aucun lien cache, aucun attribut inconnu, aucune couleur hors
 * de la palette. L'ecran l'affiche element par element (jamais comme du HTML). Le texte brut est tire du document
 * pour tout le reste (longueur minimale, recherche, ancien affichage).
 */
class RichText
{
    /** Couleurs du texte (memes cles et valeurs que frontend/src/rich/palette.ts). */
    public const COLORS = ['#c0392b', '#d35400', '#b7950b', '#1e8449', '#0d5f57', '#1f618d', '#7d3c98', '#5d6d7e'];

    /** Surlignages (couleurs claires, le texte reste lisible). */
    public const HIGHLIGHTS = ['#fff3a3', '#ffd8a8', '#c3f0ca', '#c5e4ff', '#f3d1ff', '#ffd1dc'];

    private const BLOCKS = ['paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote', 'horizontalRule'];

    private const MARKS = ['bold', 'italic', 'underline', 'strike', 'textStyle', 'highlight'];

    private const MAX_DEPTH = 8;

    private const MAX_NODES = 4000;

    /** Taille du document recu (octets), bien au-dela d'un usage normal. */
    public const MAX_BYTES = 120_000;

    /**
     * Document nettoye et son texte brut. Un document vide donne [null, ''].
     *
     * @return array{0: array<string, mixed>|null, 1: string}
     *
     * @throws ValidationException document illisible ou trop long
     */
    public static function clean(mixed $doc, int $maxChars, string $field): array
    {
        if (! is_array($doc) || ($doc['type'] ?? null) !== 'doc') {
            throw ValidationException::withMessages([$field => 'Ce texte n’a pas pu être lu. Rechargez la page puis réessayez.']);
        }
        if (strlen((string) json_encode($doc)) > self::MAX_BYTES) {
            throw ValidationException::withMessages([$field => 'Ce texte est trop long.']);
        }
        $count = 0;
        $content = self::children($doc['content'] ?? [], 'doc', 1, $count, $field);
        // Paragraphes vides en fin de texte : retires.
        while ($content && self::isEmptyBlock(end($content))) {
            array_pop($content);
        }
        $clean = ['type' => 'doc', 'content' => $content];
        $text = self::text($clean);
        if (mb_strlen($text) > $maxChars) {
            throw ValidationException::withMessages([$field => "Ce texte est trop long : {$maxChars} caractères au plus."]);
        }

        return $text === '' ? [null, ''] : [$clean, $text];
    }

    /**
     * Texte brut d'un document : un bloc par ligne, listes avec leurs puces, citations precedees de « > ».
     *
     * @param  array<string, mixed>|null  $doc
     */
    public static function text(?array $doc): string
    {
        if (! $doc) {
            return '';
        }
        $lines = [];
        self::lines($doc['content'] ?? [], $lines, '');

        return trim(implode("\n", $lines));
    }

    /** Document simple a partir d'un ancien texte (un paragraphe par ligne). */
    public static function fromText(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        return ['type' => 'doc', 'content' => array_map(fn (string $line) => $line === ''
            ? ['type' => 'paragraph']
            : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]], explode("\n", str_replace(["\r\n", "\r"], "\n", $text)))];
    }

    // ------------------------------------------------------------------ Reconstruction

    /**
     * @param  mixed  $items
     * @return array<int, array<string, mixed>>
     */
    private static function children(mixed $items, string $parent, int $depth, int &$count, string $field): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            self::refuse($field);
        }
        if ($depth > self::MAX_DEPTH) {
            self::refuse($field);
        }
        $out = [];
        foreach ($items as $node) {
            if (++$count > self::MAX_NODES || ! is_array($node) || ! is_string($node['type'] ?? null)) {
                self::refuse($field);
            }
            $type = $node['type'];
            $inline = in_array($parent, ['paragraph', 'heading'], true);
            if ($inline) {
                if ($type === 'text') {
                    $text = self::cleanText((string) ($node['text'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    $clean = ['type' => 'text', 'text' => $text];
                    if ($marks = self::marks($node['marks'] ?? [])) {
                        $clean['marks'] = $marks;
                    }
                    $out[] = $clean;
                } elseif ($type === 'hardBreak') {
                    $out[] = ['type' => 'hardBreak'];
                } else {
                    self::refuse($field);
                }

                continue;
            }
            // Blocs : la liste ne contient que des elements de liste, et un element de liste que des blocs.
            $allowed = match ($parent) {
                'bulletList', 'orderedList' => ['listItem'],
                default => array_diff(self::BLOCKS, ['listItem']),
            };
            if (! in_array($type, $allowed, true)) {
                self::refuse($field);
            }
            $clean = ['type' => $type];
            if ($type === 'heading') {
                $level = (int) ($node['attrs']['level'] ?? 2);
                $clean['attrs'] = ['level' => in_array($level, [2, 3], true) ? $level : 2];
            }
            if ($type === 'orderedList') {
                $start = (int) ($node['attrs']['start'] ?? 1);
                $clean['attrs'] = ['start' => max(1, min(999, $start))];
            }
            if ($type !== 'horizontalRule') {
                $content = self::children($node['content'] ?? [], $type, $depth + 1, $count, $field);
                if (in_array($type, ['bulletList', 'orderedList', 'listItem', 'blockquote'], true) && $content === []) {
                    continue; // liste ou citation vide : rien a garder
                }
                if ($content !== []) {
                    $clean['content'] = $content;
                }
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * Styles gardes : gras, italique, souligne, barre, couleur et surlignage de la palette. Le reste est ignore.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function marks(mixed $marks): array
    {
        if (! is_array($marks)) {
            return [];
        }
        $out = [];
        foreach ($marks as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;
            if (! in_array($type, self::MARKS, true) || isset($out[$type])) {
                continue;
            }
            if ($type === 'textStyle' || $type === 'highlight') {
                $color = strtolower(trim((string) ($mark['attrs']['color'] ?? '')));
                if (! in_array($color, $type === 'textStyle' ? self::COLORS : self::HIGHLIGHTS, true)) {
                    continue;
                }
                $out[$type] = ['type' => $type, 'attrs' => ['color' => $color]];

                continue;
            }
            $out[$type] = ['type' => $type];
        }

        return array_values($out);
    }

    /** Caracteres de controle et d'inversion de sens retires (ils servent a deguiser un texte). */
    private static function cleanText(string $text): string
    {
        return (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
    }

    private static function isEmptyBlock(array $block): bool
    {
        return ($block['type'] ?? null) === 'paragraph' && empty($block['content']);
    }

    private static function refuse(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Cette mise en forme n’est pas prise en charge. Rechargez la page puis réessayez.']);
    }

    // ------------------------------------------------------------------ Texte brut

    /** @param array<int, array<string, mixed>> $nodes */
    private static function lines(array $nodes, array &$lines, string $prefix): void
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            if ($type === 'paragraph' || $type === 'heading') {
                $lines[] = $prefix.self::inline($node['content'] ?? []);
            } elseif ($type === 'blockquote') {
                self::lines($node['content'] ?? [], $lines, $prefix.'> ');
            } elseif ($type === 'bulletList' || $type === 'orderedList') {
                $n = (int) ($node['attrs']['start'] ?? 1);
                foreach ($node['content'] ?? [] as $item) {
                    $bullet = $type === 'bulletList' ? '- ' : ($n++).'. ';
                    $inner = [];
                    self::lines($item['content'] ?? [], $inner, '');
                    foreach ($inner as $i => $line) {
                        $lines[] = $prefix.($i === 0 ? $bullet : str_repeat(' ', mb_strlen($bullet))).$line;
                    }
                }
            } elseif ($type === 'horizontalRule') {
                $lines[] = $prefix.'---';
            }
        }
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private static function inline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= ($node['type'] ?? '') === 'hardBreak' ? "\n" : (string) ($node['text'] ?? '');
        }

        return $out;
    }
}
