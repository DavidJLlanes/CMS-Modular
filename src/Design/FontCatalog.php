<?php
declare(strict_types=1);

namespace LuxAeterna\Design;

use LuxAeterna\Core\LuxException;

/**
 * Catálogo local de fuentes: components/fonts/<slug>/font.json + archivos WOFF2 + licencia. No hay peticiones a
 * terceros en la web publicada: las fuentes se sirven desde el propio dominio y solo las variantes usadas.
 */
final class FontCatalog
{
    private const STACKS = [
        'sans-serif' => 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif',
        'serif' => 'Georgia,"Times New Roman",Times,serif',
        'monospace' => 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
    ];
    public const SYSTEM_STACK = self::STACKS['sans-serif'];
    /** Caracteres que debe cubrir una fuente para cada idioma habilitado (el español es siempre obligatorio). */
    public const REQUIRED = [
        'es' => 'áéíóúüñÁÉÍÓÚÜÑ¿¡«»€—…',
        'ca' => 'àèòçÇ·ïÏ',
        'gl' => 'ñÑáéíóú',
        'eu' => 'ñÑüÜ',
        'en' => 'abcXYZ',
    ];
    private const LICENSES = ['OFL-1.1', 'Apache-2.0', 'UFL-1.0'];

    /** @var array<string,array<string,mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly string $dir)
    {
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        foreach (@scandir($this->dir) ?: [] as $slug) {
            if ($slug[0] === '.' || !preg_match('/^[a-z0-9-]{1,40}$/', $slug) || !is_file("$this->dir/$slug/font.json") || is_link("$this->dir/$slug")) {
                continue;
            }
            $f = $this->load($slug);
            if ($f !== null) {
                $out[$slug] = $f;
            }
        }
        ksort($out);
        return $this->cache = $out;
    }

    /** @return array<string,mixed>|null */
    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /** @return array<string,mixed>|null */
    private function load(string $slug): ?array
    {
        $base = "$this->dir/$slug";
        $m = json_decode((string)@file_get_contents("$base/font.json"), true);
        if (!is_array($m) || ($m['schema'] ?? null) !== 1 || ($m['slug'] ?? '') !== $slug || !is_string($m['family'] ?? null) || !preg_match('/^[A-Za-z0-9 ]{1,60}$/', $m['family'])) {
            return null;
        }
        if (!in_array($m['license'] ?? '', self::LICENSES, true) || !is_string($m['license_file'] ?? null) || !is_file("$base/{$m['license_file']}") || !isset(self::STACKS[$m['category'] ?? ''])) {
            return null;
        }
        $range = '';
        foreach ((array)($m['subsets'] ?? []) as $s) {
            if (is_array($s) && ($s['name'] ?? '') === 'latin' && preg_match('/^U\+[0-9A-Fa-f?-]+(,U\+[0-9A-Fa-f?-]+)*$/', (string)($s['unicode_range'] ?? ''))) {
                $range = (string)$s['unicode_range'];
            }
        }
        if ($range === '') {
            return null;
        }
        $variants = [];
        foreach ((array)($m['variants'] ?? []) as $v) {
            $file = is_array($v) ? ($v['file'] ?? null) : null;
            if (!is_string($file) || !preg_match('/^[a-z0-9-]+\.woff2$/', $file) || !in_array($v['weight'] ?? 0, [100, 200, 300, 400, 500, 600, 700, 800, 900], true)
                || !in_array($v['style'] ?? '', ['normal', 'italic'], true) || !is_file("$base/$file") || is_link("$base/$file") || filesize("$base/$file") > 500000
                || file_get_contents("$base/$file", false, null, 0, 4) !== 'wOF2') {
                continue;
            }
            $variants[] = ['weight' => (int)$v['weight'], 'style' => (string)$v['style'], 'file' => $file];
        }
        if ($variants === []) {
            return null;
        }
        return ['slug' => $slug, 'family' => $m['family'], 'category' => $m['category'], 'license' => $m['license'], 'license_file' => $m['license_file'],
            'source' => is_string($m['source'] ?? null) ? $m['source'] : '', 'unicode_range' => $range, 'variants' => $variants];
    }

    /** Pila CSS: familia local + reserva genérica. 'system' = pila de sistema. */
    public function stack(string $slug): string
    {
        if ($slug === 'system' || $this->get($slug) === null) {
            return self::SYSTEM_STACK;
        }
        $f = $this->get($slug);
        return '"' . $f['family'] . '",' . self::STACKS[$f['category']];
    }

    /** @param list<string> $langs @return list<string> caracteres requeridos que la fuente NO cubre */
    public function missingChars(string $slug, array $langs): array
    {
        $f = $this->get($slug);
        if ($f === null) {
            return [];
        }
        $ranges = [];
        foreach (explode(',', $f['unicode_range']) as $r) {
            $r = substr($r, 2);
            [$a, $b] = str_contains($r, '-') ? explode('-', $r, 2) : [$r, $r];
            $ranges[] = [hexdec($a), hexdec($b)];
        }
        $need = self::REQUIRED['es'];
        foreach ($langs as $l) {
            $need .= self::REQUIRED[$l] ?? '';
        }
        $missing = [];
        foreach (array_unique(mb_str_split($need)) as $ch) {
            $cp = mb_ord($ch);
            $ok = false;
            foreach ($ranges as [$a, $b]) {
                if ($cp >= $a && $cp <= $b) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $missing[] = $ch;
            }
        }
        return $missing;
    }

    /**
     * Variantes que hay que publicar para unos usos (rol → [familia, [pesos], estilos]); si falta el peso exacto se usa el más cercano.
     * @param list<array{family:string,weights:list<int>,styles:list<string>}> $uses
     * @return list<array<string,mixed>> variantes con 'slug','family','weight','style','file','path'
     */
    public function used(array $uses): array
    {
        $out = [];
        foreach ($uses as $u) {
            $f = $this->get($u['family']);
            if ($f === null) {
                continue;
            }
            foreach ($u['weights'] as $w) {
                foreach ($u['styles'] as $st) {
                    $cands = array_filter($f['variants'], static fn(array $v): bool => $v['style'] === $st);
                    if ($cands === []) {
                        continue;
                    }
                    usort($cands, static fn(array $a, array $b): int => abs($a['weight'] - $w) <=> abs($b['weight'] - $w));
                    $v = $cands[0] + ['slug' => $f['slug'], 'family' => $f['family'], 'range' => $f['unicode_range'], 'path' => "$this->dir/{$f['slug']}/{$cands[0]['file']}"];
                    $out[$f['slug'] . $v['weight'] . $v['style']] = $v;
                }
            }
        }
        return array_values($out);
    }

    /** Verifica un WOFF2 y sus metadatos; lo usa `bin/lux fonts:check`. @return list<string> */
    public function problems(): array
    {
        $p = [];
        foreach (@scandir($this->dir) ?: [] as $slug) {
            if ($slug[0] !== '.' && is_dir("$this->dir/$slug") && $this->get($slug) === null) {
                $p[] = "$slug: font.json, licencia o archivos WOFF2 no válidos.";
            }
        }
        return $p;
    }

    /** Interpreta el CSS de Google Fonts (css2) y devuelve los bloques @font-face del subconjunto latino. @return list<array{weight:int,style:string,url:string,range:string}> */
    public static function parseGoogleCss(string $css): array
    {
        $out = [];
        if (!preg_match_all('#/\*\s*([a-z0-9-]+)\s*\*/\s*@font-face\s*\{([^}]*)\}#i', $css, $blocks, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($blocks as $b) {
            if ($b[1] !== 'latin') {
                continue;
            }
            if (preg_match('/font-style:\s*(normal|italic)/', $b[2], $s) && preg_match('/font-weight:\s*(\d+)/', $b[2], $w)
                && preg_match('#src:\s*url\((https://fonts\.gstatic\.com/[A-Za-z0-9/_.-]+\.woff2)\)#', $b[2], $u) && preg_match('/unicode-range:\s*([^;]+);/', $b[2], $r)) {
                $out[] = ['weight' => (int)$w[1], 'style' => $s[1], 'url' => $u[1], 'range' => trim($r[1])];
            }
        }
        return $out;
    }

    /**
     * Descarga una familia desde Google Fonts al catálogo (la ejecuta el OPERADOR con red; la web publicada nunca llama a terceros).
     * @param list<int> $weights @return string slug
     */
    public function importFromGoogle(string $family, array $weights, string $license, string $category): string
    {
        if (!preg_match('/^[A-Za-z0-9 ]{1,60}$/', $family) || !in_array($license, self::LICENSES, true) || !isset(self::STACKS[$category])) {
            throw new LuxException('Familia, licencia o categoría no válidas.');
        }
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($family)), '-');
        $w = implode(';', array_map(static fn(int $x): string => "0,$x", $weights)) . ';' . implode(';', array_map(static fn(int $x): string => "1,$x", [400]));
        $url = 'https://fonts.googleapis.com/css2?family=' . rawurlencode($family) . ':ital,wght@' . $w . '&display=swap';
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36\r\n", 'timeout' => 15]]);
        $css = @file_get_contents($url, false, $ctx);
        if ($css === false) {
            throw new LuxException('No se pudo consultar Google Fonts (¿hay conexión y el nombre es exacto?).');
        }
        $faces = self::parseGoogleCss($css);
        if ($faces === []) {
            throw new LuxException('Google Fonts no devolvió variantes latinas para esa familia.');
        }
        $dir = "$this->dir/$slug";
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new LuxException('No se pudo crear la carpeta de la fuente.');
        }
        $variants = [];
        foreach ($faces as $f) {
            $bin = @file_get_contents($f['url'], false, $ctx);
            if ($bin === false || !str_starts_with($bin, 'wOF2') || strlen($bin) > 500000) {
                throw new LuxException('Descarga de fuente no válida.');
            }
            $file = "$slug-latin-{$f['weight']}-{$f['style']}.woff2";
            file_put_contents("$dir/$file", $bin);
            $variants[] = ['weight' => $f['weight'], 'style' => $f['style'], 'file' => $file];
        }
        file_put_contents("$dir/LICENSE.txt", "Familia $family, obtenida de https://fonts.google.com/specimen/" . rawurlencode($family) . "\nLicencia declarada por el operador: $license. Revisa el texto completo de la licencia en el repositorio de la fuente.\n");
        file_put_contents("$dir/font.json", json_encode(['schema' => 1, 'family' => $family, 'slug' => $slug, 'category' => $category, 'license' => $license, 'license_file' => 'LICENSE.txt',
            'source' => 'https://fonts.google.com/specimen/' . rawurlencode($family), 'subsets' => [['name' => 'latin', 'unicode_range' => $faces[0]['range']]], 'variants' => $variants], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->cache = null;
        return $slug;
    }
}
