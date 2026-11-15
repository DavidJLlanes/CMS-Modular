<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

use LuxAeterna\Core\LuxException;

/**
 * Esquema 2 de bloques: valida y normaliza con lista blanca (los atributos desconocidos se descartan, el HTML no existe
 * en el modelo). Los bloques de texto sin formato conservan la forma sencilla de la Fase 3 (`text`).
 */
final class BlockValidator
{
    public const MAX_BLOCKS = 500;
    public const MAX_NESTED = 200;
    public const MAX_TEXT = 20000;
    public const REF = '/^[a-z0-9][a-z0-9_-]{2,63}$/';
    public const ALIGN = ['left', 'center', 'right', 'justify'];
    public const STYLE = [
        'size' => ['sm', 'lg', 'xl'], 'weight' => ['medium', 'bold'], 'line' => ['tight', 'loose'], 'letter' => ['wide', 'wider'],
        'family' => ['body', 'heading'], 'spacing' => ['compact', 'loose'],
    ];
    public const LAYOUTS = ['1-1' => 2, '1-2' => 2, '2-1' => 2, '1-1-1' => 3, '1-2-1' => 3, '1-1-1-1' => 4];
    public const NESTABLE = ['paragraph', 'heading', 'list', 'quote', 'image', 'button', 'separator', 'table'];

    /** @var array<string,array{validate:callable,render:callable}> extensiones registradas (mapas, etc.) */
    private static array $extensions = [];

    /**
     * Punto de extensión: un plugin registra un tipo de bloque con su validador (datos → datos limpios) y su
     * generador de HTML. Sin registro, un bloque `extension` se rechaza.
     */
    public static function registerExtension(string $kind, callable $validate, callable $render): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $kind)) {
            throw new LuxException('Nombre de extensión de bloque no válido.');
        }
        self::$extensions[$kind] = ['validate' => $validate, 'render' => $render];
    }

    public static function resetExtensions(): void
    {
        self::$extensions = [];
    }

    /** @return array{validate:callable,render:callable}|null */
    public static function extension(string $kind): ?array
    {
        return self::$extensions[$kind] ?? null;
    }

    /**
     * @param mixed $blocks
     * @return list<array<string,mixed>>
     */
    public static function validate(mixed $blocks, int $depth = 0, int &$budget = self::MAX_NESTED): array
    {
        if (!is_array($blocks) || count($blocks) > self::MAX_BLOCKS) {
            throw new LuxException('El cuerpo no es válido o tiene demasiados bloques (máx. ' . self::MAX_BLOCKS . ').');
        }
        $out = [];
        foreach (array_values($blocks) as $i => $b) {
            $n = $i + 1;
            if ($depth > 0 && --$budget < 0) {
                throw new LuxException('Demasiados bloques dentro de las columnas.');
            }
            $out[] = self::one(is_array($b) ? $b : [], $n, $depth, $budget);
        }
        return $out;
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    private static function one(array $b, int $n, int $depth, int &$budget): array
    {
        $type = (string)($b['type'] ?? '');
        if ($depth > 0 && !in_array($type, self::NESTABLE, true)) {
            throw new LuxException("Bloque $n: este tipo no puede ir dentro de columnas.");
        }
        switch ($type) {
            case 'paragraph':
            case 'quote':
                $o = ['type' => $type] + self::richText($b, $n);
                if ($type === 'quote' && isset($b['cite']) && trim((string)$b['cite']) !== '') {
                    $o['cite'] = self::plain($b['cite'], $n, 200);
                }
                return $o + self::textStyle($b, $n);
            case 'heading':
                $level = (int)($b['level'] ?? 2);
                if ($level < 1 || $level > 6) {
                    throw new LuxException("Bloque $n: el nivel del título debe estar entre 1 y 6.");
                }
                return ['type' => 'heading', 'level' => $level] + self::richText($b, $n) + self::textStyle($b, $n, false);
            case 'list':
                return self::list($b, $n);
            case 'image':
                $o = ['type' => 'image', 'ref' => self::ref($b['ref'] ?? '', $n), 'alt' => self::plain($b['alt'] ?? '', $n, 300), 'caption' => self::plain($b['caption'] ?? '', $n, 500)];
                if (in_array($b['align'] ?? '', ['left', 'center', 'right'], true)) {
                    $o['align'] = $b['align'];
                }
                return $o;
            case 'gallery':
                return ['type' => 'gallery', 'ref' => self::ref($b['ref'] ?? '', $n)];
            case 'button':
                $label = self::plain($b['label'] ?? '', $n, 80);
                if ($label === '') {
                    throw new LuxException("Bloque $n: el botón necesita un texto.");
                }
                $o = ['type' => 'button', 'label' => $label, 'href' => Inline::link($b['href'] ?? '', $n), 'variant' => in_array($b['variant'] ?? '', ['secondary', 'outline'], true) ? $b['variant'] : 'primary'];
                if (in_array($b['align'] ?? '', ['center', 'right'], true)) {
                    $o['align'] = $b['align'];
                }
                return $o;
            case 'separator':
                return ['type' => 'separator', 'style' => in_array($b['style'] ?? '', ['dots', 'space'], true) ? $b['style'] : 'line'];
            case 'columns':
                return self::columns($b, $n, $budget);
            case 'table':
                return self::table($b, $n);
            case 'extension':
                $kind = (string)($b['kind'] ?? '');
                $ext = self::$extensions[$kind] ?? null;
                if ($ext === null) {
                    throw new LuxException("Bloque $n: la extensión «" . substr($kind, 0, 31) . '» no está instalada.');
                }
                return ['type' => 'extension', 'kind' => $kind, 'data' => ($ext['validate'])(is_array($b['data'] ?? null) ? $b['data'] : [], $n)];
            default:
                throw new LuxException("Bloque $n: tipo no soportado.");
        }
    }

    /** Texto plano (alt, pie, etiquetas): sin controles y con longitud acotada. */
    public static function plain(mixed $t, int $n, int $max): string
    {
        if (!is_string($t) || mb_strlen($t) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $t)) {
            throw new LuxException("Bloque $n: texto no válido o demasiado largo (máx. $max caracteres).");
        }
        return trim($t);
    }

    /** @param array<string,mixed> $b @return array<string,mixed> `text` si no hay formato, `inlines` si lo hay */
    private static function richText(array $b, int $n): array
    {
        $runs = Inline::validate(array_key_exists('inlines', $b) ? $b['inlines'] : (string)($b['text'] ?? ''), $n, self::MAX_TEXT);
        if (!Inline::formatted($runs)) {
            return ['text' => trim(Inline::plain($runs))];
        }
        return ['inlines' => $runs];
    }

    /** @param array<string,mixed> $b @return array<string,mixed> alineación y tipografía como valores admitidos */
    private static function textStyle(array $b, int $n, bool $withStyle = true): array
    {
        $o = [];
        if (in_array($b['align'] ?? '', self::ALIGN, true)) {
            $o['align'] = $b['align'];
        }
        if (!$withStyle || !is_array($b['style'] ?? null)) {
            return $o;
        }
        $s = [];
        foreach (self::STYLE as $k => $allowed) {
            if (in_array($b['style'][$k] ?? '', $allowed, true)) {
                $s[$k] = $b['style'][$k];
            }
        }
        if (!empty($b['style']['italic'])) {
            $s['italic'] = true;
        }
        if (isset($b['style']['color']) && $b['style']['color'] !== '') {
            $s['color'] = Inline::color($b['style']['color'], $n);
        }
        return $s === [] ? $o : $o + ['style' => $s];
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    private static function list(array $b, int $n): array
    {
        $items = $b['items'] ?? null;
        if (!is_array($items) || $items === [] || count($items) > 200) {
            throw new LuxException("Bloque $n: la lista debe tener entre 1 y 200 elementos.");
        }
        $out = [];
        foreach (array_values($items) as $it) {
            $runs = Inline::validate(is_array($it) && array_key_exists('inlines', $it) ? $it['inlines'] : (is_array($it) ? (string)($it['text'] ?? '') : $it), $n, 2000);
            $out[] = Inline::formatted($runs) ? ['inlines' => $runs] : trim(Inline::plain($runs));
        }
        $o = ['type' => 'list', 'items' => $out];
        return !empty($b['ordered']) ? $o + ['ordered' => true] : $o;
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    private static function columns(array $b, int $n, int &$budget): array
    {
        $layout = (string)($b['layout'] ?? '1-1');
        if (!isset(self::LAYOUTS[$layout])) {
            throw new LuxException("Bloque $n: distribución de columnas no válida.");
        }
        $cols = $b['cols'] ?? null;
        if (!is_array($cols) || count($cols) !== self::LAYOUTS[$layout]) {
            throw new LuxException("Bloque $n: el número de columnas no coincide con la distribución.");
        }
        $out = [];
        foreach (array_values($cols) as $c) {
            if (is_array($c) && count($c) > 50) {
                throw new LuxException("Bloque $n: demasiados bloques en una columna.");
            }
            $out[] = self::validate($c, 1, $budget);
        }
        return ['type' => 'columns', 'layout' => $layout, 'gap' => in_array($b['gap'] ?? '', ['s', 'l'], true) ? $b['gap'] : 'm', 'valign' => ($b['valign'] ?? '') === 'center' ? 'center' : 'top', 'cols' => $out];
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    private static function table(array $b, int $n): array
    {
        $rows = $b['rows'] ?? null;
        if (!is_array($rows) || $rows === [] || count($rows) > 50 || !is_array(reset($rows)) || count(reset($rows)) < 1 || count(reset($rows)) > 12) {
            throw new LuxException("Bloque $n: la tabla debe tener entre 1 y 50 filas y entre 1 y 12 columnas.");
        }
        $w = count(reset($rows));
        $out = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row) || count($row) !== $w) {
                throw new LuxException("Bloque $n: todas las filas de la tabla deben tener el mismo número de celdas.");
            }
            $cells = [];
            foreach (array_values($row) as $cell) {
                $runs = Inline::validate(is_array($cell) && array_key_exists('inlines', $cell) ? $cell['inlines'] : (is_array($cell) ? (string)($cell['text'] ?? '') : $cell), $n, 500);
                $cells[] = Inline::formatted($runs) ? ['inlines' => $runs] : trim(Inline::plain($runs));
            }
            $out[] = $cells;
        }
        $aligns = [];
        for ($i = 0; $i < $w; $i++) {
            $a = is_array($b['aligns'] ?? null) ? ($b['aligns'][$i] ?? '') : '';
            $aligns[] = in_array($a, ['center', 'right'], true) ? $a : 'left';
        }
        return ['type' => 'table', 'caption' => self::plain($b['caption'] ?? '', $n, 200), 'header_row' => !empty($b['header_row']), 'header_col' => !empty($b['header_col']), 'aligns' => $aligns, 'rows' => $out];
    }

    public static function ref(mixed $r, int $n): string
    {
        if (!is_string($r) || !preg_match(self::REF, $r)) {
            throw new LuxException("Bloque $n: referencia de imagen o galería no válida.");
        }
        return $r;
    }
}
