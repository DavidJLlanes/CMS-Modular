<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

use LuxAeterna\Core\LuxException;

/**
 * Biblioteca de bloques como estructura serializable (schema 1). Las imágenes y galerías se referencian por
 * identificador estable (`ref`), nunca por ruta, para sobrevivir a cambios de almacenamiento (Fase 6).
 *
 * Sintaxis de texto del editor (sin JavaScript): bloques separados por línea en blanco.
 *   ## Título (## a ######) · - elemento (lista) · > cita · [imagen:ref|texto alternativo|pie] · [galeria:ref]
 */
final class Blocks
{
    public const MAX_BLOCKS = 500;
    public const MAX_TEXT = 20000;
    private const REF = '/^[a-z0-9][a-z0-9_-]{2,63}$/';

    /**
     * @param mixed $blocks
     * @return list<array<string,mixed>>
     */
    public static function validate(mixed $blocks): array
    {
        if (!is_array($blocks) || count($blocks) > self::MAX_BLOCKS) {
            throw new LuxException('El cuerpo no es válido o tiene demasiados bloques (máx. ' . self::MAX_BLOCKS . ').');
        }
        $out = [];
        foreach (array_values($blocks) as $i => $b) {
            $n = $i + 1;
            $type = is_array($b) ? (string)($b['type'] ?? '') : '';
            switch ($type) {
                case 'paragraph':
                case 'quote':
                    $out[] = ['type' => $type, 'text' => self::text($b['text'] ?? '', $n)];
                    break;
                case 'heading':
                    $level = (int)($b['level'] ?? 2);
                    if ($level < 2 || $level > 6) {
                        throw new LuxException("Bloque $n: el nivel del título debe estar entre 2 y 6.");
                    }
                    $out[] = ['type' => 'heading', 'level' => $level, 'text' => self::text($b['text'] ?? '', $n)];
                    break;
                case 'list':
                    $items = $b['items'] ?? null;
                    if (!is_array($items) || $items === [] || count($items) > 200) {
                        throw new LuxException("Bloque $n: la lista debe tener entre 1 y 200 elementos.");
                    }
                    $out[] = ['type' => 'list', 'items' => array_map(static fn($t): string => self::text($t, $n), array_values($items))];
                    break;
                case 'image':
                    $out[] = ['type' => 'image', 'ref' => self::ref($b['ref'] ?? '', $n), 'alt' => self::text($b['alt'] ?? '', $n, 300), 'caption' => self::text($b['caption'] ?? '', $n, 500)];
                    break;
                case 'gallery':
                    $out[] = ['type' => 'gallery', 'ref' => self::ref($b['ref'] ?? '', $n)];
                    break;
                default:
                    throw new LuxException("Bloque $n: tipo no soportado.");
            }
        }
        return $out;
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
                'heading' => str_repeat('#', (int)$b['level']) . ' ' . $b['text'],
                'list' => implode("\n", array_map(static fn(string $i): string => '- ' . $i, $b['items'])),
                'quote' => '> ' . $b['text'],
                'image' => '[imagen:' . $b['ref'] . '|' . $b['alt'] . '|' . $b['caption'] . ']',
                'gallery' => '[galeria:' . $b['ref'] . ']',
                default => preg_match('/^(#{2,6} |- |> |\[imagen:|\[galeria:|\\\\)/', (string)$b['text']) ? '\\' . $b['text'] : (string)$b['text'],
            };
        }
        return implode("\n\n", $out);
    }

    /** @param list<array<string,mixed>> $blocks @return list<string> tipos que requieren módulos aún no disponibles */
    public static function mediaRefs(array $blocks): array
    {
        return array_values(array_map(static fn(array $b): string => (string)$b['ref'], array_filter($blocks, static fn(array $b): bool => in_array($b['type'], ['image', 'gallery'], true))));
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

    private static function text(mixed $t, int $n, int $max = self::MAX_TEXT): string
    {
        if (!is_string($t) || mb_strlen($t) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $t)) {
            throw new LuxException("Bloque $n: texto no válido o demasiado largo (máx. $max caracteres).");
        }
        return trim($t);
    }

    private static function ref(mixed $r, int $n): string
    {
        if (!is_string($r) || !preg_match(self::REF, $r)) {
            throw new LuxException("Bloque $n: referencia de imagen o galería no válida.");
        }
        return $r;
    }
}
