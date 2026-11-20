<?php
declare(strict_types=1);

namespace CMSModular\Content;

use CMSModular\Core\LuxException;

/**
 * Biblioteca de bloques como estructura serializable (schema 2). Las imágenes y galerías se referencian por
 * identificador estable (`ref`), nunca por ruta, para sobrevivir a cambios de almacenamiento (Fase 6).
 *
 * Sintaxis de texto del editor (sin JavaScript): bloques separados por línea en blanco.
 *   ## Título (## a ######) · - elemento (lista) · > cita · [imagen:ref|texto alternativo|pie] · [galeria:ref]
 */
final class Blocks
{
    public const MAX_BLOCKS = BlockValidator::MAX_BLOCKS;
    public const MAX_TEXT = BlockValidator::MAX_TEXT;

    /**
     * Valida y normaliza (esquema 2: formato en línea, columnas, tablas, botones…). Ver BlockValidator.
     * @param mixed $blocks
     * @return list<array<string,mixed>>
     */
    public static function validate(mixed $blocks): array
    {
        return BlockValidator::validate($blocks);
    }

    /** @return list<array<string,mixed>> */
    public static function fromText(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = [];
        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            $lines = array_map('rtrim', explode("\n", $chunk));
            if (preg_match('/^(#{2,6}) +(.+)$/', $lines[0], $m) && count($lines) === 1) {
                $blocks[] = ['type' => 'heading', 'level' => strlen($m[1]), 'text' => $m[2]];
            } elseif (self::all($lines, '/^- +/')) {
                $blocks[] = ['type' => 'list', 'items' => array_map(static fn(string $l): string => trim(substr($l, 2)), $lines)];
            } elseif (self::all($lines, '/^> ?/')) {
                $blocks[] = ['type' => 'quote', 'text' => implode(' ', array_map(static fn(string $l): string => trim(substr($l, 1)), $lines))];
            } elseif (count($lines) === 1 && preg_match('/^\[imagen:([^\]|]*)(?:\|([^\]|]*))?(?:\|([^\]]*))?\]$/u', $lines[0], $m)) {
                $blocks[] = ['type' => 'image', 'ref' => trim($m[1]), 'alt' => trim($m[2] ?? ''), 'caption' => trim($m[3] ?? '')];
            } elseif (count($lines) === 1 && preg_match('/^\[galeria:([^\]]*)\]$/u', $lines[0], $m)) {
                $blocks[] = ['type' => 'gallery', 'ref' => trim($m[1])];
            } else {
                $p = implode(' ', array_map('trim', $lines));
                $blocks[] = ['type' => 'paragraph', 'text' => str_starts_with($p, '\\') ? substr($p, 1) : $p];
            }
        }
        return self::validate($blocks);
    }

    /** @param list<array<string,mixed>> $blocks */
    public static function toText(array $blocks): string
    {
        $out = [];
        foreach ($blocks as $b) {
            $out[] = match ($b['type'] ?? '') {
                'heading' => str_repeat('#', max(2, (int)$b['level'])) . ' ' . ($b['text'] ?? Inline::plain((array)($b['inlines'] ?? []))),
                'list' => implode("\n", array_map(static fn(mixed $i): string => '- ' . (is_string($i) ? $i : Inline::plain((array)($i['inlines'] ?? []))), $b['items'])),
                'quote' => '> ' . ($b['text'] ?? Inline::plain((array)($b['inlines'] ?? []))),
                'image' => '[imagen:' . $b['ref'] . '|' . $b['alt'] . '|' . $b['caption'] . ']',
                'gallery' => '[galeria:' . $b['ref'] . ']',
                'paragraph' => preg_match('/^(#{2,6} |- |> |\[imagen:|\[galeria:|\\\\)/', (string)($b['text'] ?? '')) ? '\\' . $b['text'] : (string)($b['text'] ?? Inline::plain((array)($b['inlines'] ?? []))),
                default => '',
            };
        }
        return implode("\n\n", $out);
    }

    /** Recorre los bloques, incluidos los de dentro de columnas. @param list<array<string,mixed>> $blocks @param callable(array<string,mixed>):void $fn */
    public static function walk(array $blocks, callable $fn): void
    {
        foreach ($blocks as $b) {
            $fn($b);
            foreach ((array)($b['cols'] ?? []) as $col) {
                self::walk((array)$col, $fn);
            }
        }
    }

    /** @param list<array<string,mixed>> $blocks @return list<string> referencias de imágenes y galerías (también dentro de columnas) */
    public static function mediaRefs(array $blocks): array
    {
        $refs = [];
        self::walk($blocks, static function (array $b) use (&$refs): void {
            if (in_array($b['type'], ['image', 'gallery'], true)) {
                $refs[] = (string)$b['ref'];
            }
        });
        return $refs;
    }

    /** Colores #rrggbb usados (tramos, tipografía): hace falta una clase CSS por cada uno. @param list<array<string,mixed>> $blocks @return list<string> */
    public static function colors(array $blocks): array
    {
        $out = [];
        $add = static function (mixed $c) use (&$out): void {
            if (is_string($c) && $c !== '' && $c[0] === '#') {
                $out[$c] = true;
            }
        };
        $runs = static function (array $list) use ($add): void {
            foreach ($list as $r) {
                $add($r['c'] ?? null);
            }
        };
        self::walk($blocks, static function (array $b) use ($add, $runs): void {
            $add($b['style']['color'] ?? null);
            $runs((array)($b['inlines'] ?? []));
            foreach ((array)($b['items'] ?? []) as $it) {
                $runs(is_array($it) ? (array)($it['inlines'] ?? []) : []);
            }
            foreach ((array)($b['rows'] ?? []) as $row) {
                foreach ((array)$row as $cell) {
                    $runs(is_array($cell) ? (array)($cell['inlines'] ?? []) : []);
                }
            }
        });
        $c = array_keys($out);
        sort($c);
        return $c;
    }

    /** ¿Se puede editar sin pérdida con la sintaxis de texto (sin JavaScript)? @param list<array<string,mixed>> $blocks */
    public static function isPlain(array $blocks): bool
    {
        foreach ($blocks as $b) {
            $t = $b['type'];
            $ok = match ($t) {
                'paragraph' => isset($b['text']) && !isset($b['align'], $b['style']),
                'heading' => isset($b['text']) && $b['level'] >= 2 && !isset($b['align']),
                'quote' => isset($b['text']) && !isset($b['cite'], $b['align'], $b['style']),
                'list' => empty($b['ordered']) && array_reduce($b['items'], static fn(bool $c, mixed $i): bool => $c && is_string($i), true),
                'image' => !isset($b['align']),
                'gallery' => true,
                default => false,
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $lines */
    private static function all(array $lines, string $re): bool
    {
        foreach ($lines as $l) {
            if (!preg_match($re, $l)) {
                return false;
            }
        }
        return true;
    }
}
