<?php
declare(strict_types=1);

namespace CMSModular\Design;

use CMSModular\Core\Fs;

/**
 * Detecta diseños por carpeta: components/<familia>/<carpeta>/manifest.json. Valida cada contrato, cachea el índice
 * y lo invalida cuando cambia cualquier archivo de un diseño (o al pedir refresh()).
 *
 * El contrato (schema 1) está documentado en docs/fase-5.md. Un diseño inválido NUNCA se publica: se lista como
 * error con su motivo y el resto sigue funcionando.
 */
final class DesignRegistry
{
    public const TYPES = ['header' => 'Cabecera', 'topbar' => 'Barra superior', 'hero' => 'Hero', 'footer' => 'Pie', 'gallery' => 'Galería', 'viewer' => 'Visor'];
    /** Variables de las plantillas de galería (wrapper) y de ítem. raw = HTML preparado por el generador. */
    public const GALLERY_RAW = ['items'];
    public const GALLERY_ESC = ['title', 'count'];
    public const ITEM_RAW = ['a_open', 'a_close', 'img', 'caption'];
    public const ITEM_ESC = ['index', 'alt', 'cls', 'title'];
    public const CROPS = ['free', 'square', 'landscape', 'portrait'];
    /** Marcadores de plantilla siempre disponibles. raw = HTML preparado por el generador. */
    public const STD_RAW = ['nav', 'footer_nav', 'logo'];
    public const STD_ESC = ['site_name', 'home_url', 'title', 'description', 'year'];
    private const ID = '/^[a-z][a-z0-9-]{1,40}$/';
    private const FILE = '/^[a-z0-9][a-z0-9_-]{0,40}\.[a-z0-9]{2,5}$/';
    private const MAX_FILE = 262144;
    private const SKIP = ['layouts', 'logos', 'fonts'];

    /** Ids antiguos que siguen resolviéndose tras un cambio de nombre. */
    private const RENAMED = ['cabeceraguay2' => 'cabecera-corporativa'];

    /** @var array{designs:array<string,array<string,mixed>>,errors:list<array{dir:string,message:string}>}|null */
    private ?array $mem = null;

    public function __construct(private readonly string $dir, private readonly ?string $cacheFile = null, private readonly string $luxVersion = '0.4.0')
    {
    }

    /** @return array{designs:array<string,array<string,mixed>>,errors:list<array{dir:string,message:string}>} */
    public function index(): array
    {
        if ($this->mem !== null) {
            return $this->mem;
        }
        $fp = $this->fingerprint();
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            $c = json_decode((string)@file_get_contents($this->cacheFile), true);
            if (is_array($c) && ($c['fingerprint'] ?? '') === $fp && isset($c['index'])) {
                return $this->mem = $c['index'];
            }
        }
        return $this->build($fp);
    }

    /** Reconstruye el índice ignorando la caché (botón «Detectar diseños» del panel). */
    public function refresh(): array
    {
        $this->mem = null;
        return $this->build($this->fingerprint());
    }

    /** @return array<string,mixed>|null */
    public function get(string $id): ?array
    {
        $id = self::RENAMED[$id] ?? $id;
        return $this->index()['designs'][$id] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public function byType(string $type): array
    {
        return array_filter($this->index()['designs'], static fn(array $d): bool => $d['type'] === $type);
    }

    public function path(string $id): string
    {
        return $this->dir . '/' . $this->index()['designs'][$id]['dir'];
    }

    /** Huella barata (solo stat) de todos los archivos de diseños. */
    private function fingerprint(): string
    {
        $parts = [$this->luxVersion];
        foreach (@scandir($this->dir) ?: [] as $fam) {
            if ($fam[0] === '.' || in_array($fam, self::SKIP, true) || !is_dir("$this->dir/$fam")) {
                continue;
            }
            foreach (@scandir("$this->dir/$fam") ?: [] as $d) {
                if ($d[0] === '.' || !is_dir("$this->dir/$fam/$d")) {
                    continue;
                }
                $parts[] = "$fam/$d";
                foreach (@scandir("$this->dir/$fam/$d") ?: [] as $f) {
                    if ($f[0] !== '.') {
                        $st = @lstat("$this->dir/$fam/$d/$f");
                        $parts[] = "$f:" . ($st ? $st['size'] . ':' . $st['mtime'] . ':' . ($st['mode'] & 0170000) : '?');
                    }
                }
            }
        }
        return hash('sha256', implode("\n", $parts));
    }

    /** @return array{designs:array<string,array<string,mixed>>,errors:list<array{dir:string,message:string}>} */
    private function build(string $fp): array
    {
        $designs = [];
        $errors = [];
        $dups = [];
        foreach (@scandir($this->dir) ?: [] as $fam) {
            if ($fam[0] === '.' || in_array($fam, self::SKIP, true) || !is_dir("$this->dir/$fam")) {
                continue;
            }
            foreach (@scandir("$this->dir/$fam") ?: [] as $d) {
                if ($d[0] === '.' || !is_dir("$this->dir/$fam/$d") || !is_file("$this->dir/$fam/$d/manifest.json")) {
                    continue;
                }
                $rel = "$fam/$d";
                [$design, $errs] = $this->load($rel);
                foreach ($errs as $e) {
                    $errors[] = ['dir' => $rel, 'message' => $e];
                }
                if ($design === null) {
                    continue;
                }
                if (isset($designs[$design['id']]) || isset($dups[$design['id']])) {
                    $dups[$design['id']] = true;
                    $errors[] = ['dir' => $rel, 'message' => 'Identificador duplicado: ' . $design['id'] . ' (también en ' . ($designs[$design['id']]['dir'] ?? 'otra carpeta') . '). Se ignoran ambos.'];
                    unset($designs[$design['id']]);
                    continue;
                }
                $designs[$design['id']] = $design;
            }
        }
        ksort($designs);
        $index = ['designs' => $designs, 'errors' => $errors];
        if ($this->cacheFile !== null) {
            try {
                Fs::atomicWrite($this->cacheFile, (string)json_encode(['fingerprint' => $fp, 'index' => $index], JSON_UNESCAPED_SLASHES), 0640);
            } catch (\Throwable) {
                // la caché es opcional: sin escritura se recalcula en cada petición
            }
        }
        return $this->mem = $index;
    }

    /** @return array{0:?array<string,mixed>,1:list<string>} */
    private function load(string $rel): array
    {
        $base = "$this->dir/$rel";
        $errs = [];
        // sin enlaces simbólicos en toda la carpeta del diseño
        foreach (@scandir($base) ?: [] as $f) {
            if ($f !== '.' && $f !== '..' && is_link("$base/$f")) {
                return [null, ["Enlace simbólico no permitido: $f"]];
            }
        }
        if (is_link($base) || !str_starts_with((string)realpath($base), (string)realpath($this->dir) . '/')) {
            return [null, ['La carpeta del diseño sale del directorio de componentes.']];
        }
        if (filesize("$base/manifest.json") > 65536) {
            return [null, ['manifest.json supera 64 KB.']];
        }
        $m = json_decode((string)file_get_contents("$base/manifest.json"), true);
        if (!is_array($m)) {
            return [null, ['manifest.json no es JSON válido.']];
        }
        if (($m['schema'] ?? null) !== 1) {
            $errs[] = 'schema debe ser 1.';
        }
        $id = $m['id'] ?? null;
        if (!is_string($id) || !preg_match(self::ID, $id)) {
            $errs[] = 'id no válido (minúsculas, números y guiones, 2–41 caracteres).';
        }
        if (!is_string($m['type'] ?? null) || !isset(self::TYPES[$m['type']])) {
            $errs[] = 'type debe ser uno de: ' . implode(', ', array_keys(self::TYPES)) . '.';
        }
        if (!is_string($m['name'] ?? null) || $m['name'] === '' || mb_strlen($m['name']) > 80) {
            $errs[] = 'Falta name.';
        }
        if (!is_string($m['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/', $m['version'])) {
            $errs[] = 'version debe ser MAYOR.MENOR.PARCHE.';
        }
        $min = $m['requires']['lux'] ?? '0.0.0';
        if (!is_string($min) || !preg_match('/^\d+\.\d+\.\d+$/', $min)) {
            $errs[] = 'requires.lux no válido.';
        } elseif (version_compare($min, $this->luxVersion, '>')) {
            $errs[] = "Requiere CMS-Modular $min o superior (instalado: $this->luxVersion).";
        }
        $files = [];
        $isGallery = ($m['type'] ?? '') === 'gallery';
        $isViewer = ($m['type'] ?? '') === 'viewer';
        foreach (['template' => !$isViewer, 'item' => $isGallery, 'css' => true, 'js' => false, 'preview' => false] as $k => $required) {
            $v = $m[$k] ?? null;
            if ($v === null || $v === '') {
                if ($required) {
                    $errs[] = "Falta $k.";
                }
                continue;
            }
            if (!is_string($v) || !preg_match(self::FILE, $v) || !is_file("$base/$v") || is_link("$base/$v") || filesize("$base/$v") > self::MAX_FILE) {
                $errs[] = "$k: archivo no válido, inexistente o demasiado grande (máx. 256 KB; solo nombre de archivo, sin rutas).";
                continue;
            }
            $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
            if (['template' => 'html', 'item' => 'html', 'css' => 'css', 'js' => 'js', 'preview' => 'webp'][$k] !== $ext) {
                $errs[] = "$k debe tener extensión ." . ['template' => 'html', 'item' => 'html', 'css' => 'css', 'js' => 'js', 'preview' => 'webp'][$k] . '.';
                continue;
            }
            $files[$k] = $v;
        }
        $fields = $m['fields'] ?? [];
        $errs = array_merge($errs, FieldSchema::checkDefs($fields));
        foreach (array_keys(is_array($fields) ? $fields : []) as $f) {
            if (in_array($f, array_merge(self::STD_RAW, self::STD_ESC, self::GALLERY_RAW, self::GALLERY_ESC, self::ITEM_RAW, self::ITEM_ESC), true)) {
                $errs[] = "El campo «{$f}» usa un nombre reservado.";
            }
        }
        $res = $m['resources'] ?? [];
        if (!is_array($res) || array_diff(array_keys($res), ['fonts', 'images']) !== []) {
            $errs[] = 'resources solo admite fonts e images.';
        }
        $declared = ['fonts' => [], 'images' => []];
        foreach (['fonts', 'images'] as $k) {
            foreach ((array)($res[$k] ?? []) as $r) {
                if (!is_string($r) || preg_match('#^(?:[a-z]+:)?//#i', $r) || !preg_match('/^[A-Za-z0-9 ._-]{1,80}$/', $r)) {
                    $errs[] = "resources.$k: valor no válido (no se admiten recursos remotos ni rutas).";
                } else {
                    $declared[$k][] = $r;
                }
            }
        }
        if ($errs === [] && isset($files['template'])) {
            $errs = array_merge($errs, $this->checkTemplate((string)file_get_contents("$base/{$files['template']}"), is_array($fields) ? $fields : [], (string)($m['type'] ?? ''), 'gallery'));
            if (isset($files['item'])) {
                $errs = array_merge($errs, $this->checkTemplate((string)file_get_contents("$base/{$files['item']}"), is_array($fields) ? $fields : [], 'gallery', 'item'));
            }
            if (($m['type'] ?? '') === 'hero' && !preg_match('/<h1[\s>]/i', (string)file_get_contents("$base/{$files['template']}"))) {
                $errs[] = 'Un hero debe contener el <h1> de la página.';
            }
        }
        if ($errs === [] && isset($files['css'])) {
            $errs = array_merge($errs, $this->checkCss((string)file_get_contents("$base/{$files['css']}")));
            if (isset($files['js'])) {
                $errs = array_merge($errs, $this->checkJs((string)file_get_contents("$base/{$files['js']}")));
            }
        }
        $gallery = null;
        if ($isGallery) {
            [$gallery, $gerrs] = self::checkGallery($m['gallery'] ?? null);
            $errs = array_merge($errs, $gerrs);
        }
        if ($errs !== []) {
            return [null, $errs];
        }
        $hash = hash('sha256', implode("\0", array_map(static fn(string $f): string => (string)file_get_contents("$base/$f"), array_values(array_diff_key($files, ['preview' => 1])))));
        return [[
            'id' => $id, 'type' => $m['type'], 'name' => $m['name'], 'version' => $m['version'], 'dir' => $rel,
            'description' => is_string($m['description'] ?? null) ? mb_substr($m['description'], 0, 300) : '',
            'files' => $files, 'fields' => $fields, 'defaults' => FieldSchema::defaults($fields),
            'resources' => $declared, 'gallery' => $gallery, 'hash' => substr($hash, 0, 10),
        ], []];
    }

    /**
     * Metadatos propios de un diseño de galería: recortes admitidos, tamaño de miniatura y ajuste `sizes`.
     * @return array{0:?array<string,mixed>,1:list<string>}
     */
    private static function checkGallery(mixed $g): array
    {
        if (!is_array($g)) {
            return [null, ['Un diseño de galería necesita el objeto «gallery» (crops, crop_default, thumb_px, sizes).']];
        }
        $errs = [];
        $crops = $g['crops'] ?? null;
        if (!is_array($crops) || $crops === [] || array_diff($crops, self::CROPS) !== []) {
            $errs[] = 'gallery.crops debe listar valores de: ' . implode(', ', self::CROPS) . '.';
        }
        if (!is_string($g['crop_default'] ?? null) || !in_array($g['crop_default'], is_array($crops) ? $crops : [], true)) {
            $errs[] = 'gallery.crop_default debe estar entre los recortes admitidos.';
        }
        $px = $g['thumb_px'] ?? null;
        if (!is_int($px) || $px < 120 || $px > 1600) {
            $errs[] = 'gallery.thumb_px debe ser un entero entre 120 y 1600 (ancho en pantalla de la miniatura).';
        }
        if (!is_string($g['sizes'] ?? null) || !preg_match('/^[a-z0-9 :,().%\/*+-]{1,160}$/i', $g['sizes'])) {
            $errs[] = 'gallery.sizes no válido.';
        }
        if ($errs !== []) {
            return [null, $errs];
        }
        return [['crops' => array_values($crops), 'crop_default' => $g['crop_default'], 'thumb_px' => $px, 'sizes' => $g['sizes']], []];
    }

    /** @param array<string,mixed> $fields @return list<string> */
    private function checkTemplate(string $t, array $fields, string $type = '', string $part = 'template'): array
    {
        $errs = [];
        [$stdRaw, $stdEsc] = $type === 'gallery' ? ($part === 'item' ? [self::ITEM_RAW, self::ITEM_ESC] : [self::GALLERY_RAW, self::GALLERY_ESC]) : [self::STD_RAW, self::STD_ESC];
        preg_match_all('/\{\{\{\s*([^}\s]*)\s*\}\}\}|\{\{\s*([^}\s]*)\s*\}\}/', $t, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $raw = ($m[1] ?? '') !== '';
            $n = $raw ? $m[1] : ($m[2] ?? '');
            $okRaw = in_array($n, $stdRaw, true);
            $okEsc = in_array($n, $stdEsc, true) || isset($fields[$n]);
            if ($raw && !$okRaw) {
                $errs[] = "Plantilla: «{{{ $n }}}» no permitido (solo HTML preparado: " . implode(', ', $stdRaw) . ').';
            } elseif (!$raw && !$okEsc && !$okRaw) {
                $errs[] = "Plantilla: marcador desconocido «{{ $n }}».";
            } elseif (!$raw && $okRaw) {
                $errs[] = "Plantilla: «{$n}» es HTML preparado; usa {{{ $n }}}.";
            }
        }
        if (preg_match('/<\s*(script|iframe|object|embed|form|base|meta)\b/i', $t, $m)) {
            $errs[] = 'Plantilla: etiqueta no permitida <' . strtolower($m[1]) . '> (los diseños no cargan scripts en línea ni incrustaciones).';
        }
        if (preg_match('/\son[a-z]+\s*=|javascript:|style\s*=/i', $t)) {
            $errs[] = 'Plantilla: atributos de evento, estilos en línea o javascript: no permitidos (CSP).';
        }
        if (preg_match('/(?:src|href|action|poster|srcset)\s*=\s*["\']?\s*(?:https?:)?\/\//i', $t)) {
            $errs[] = 'Plantilla: recursos remotos no permitidos; los diseños son autocontenidos.';
        }
        return array_values(array_unique($errs));
    }

    /** @return list<string> */
    private function checkCss(string $css): array
    {
        $errs = [];
        if (preg_match('/@import\b/i', $css)) {
            $errs[] = 'CSS: @import no permitido.';
        }
        if (preg_match('/url\(\s*[\'"]?\s*(?:https?:)?\/\//i', $css)) {
            $errs[] = 'CSS: recursos remotos no permitidos.';
        }
        if (preg_match('/expression\s*\(|behavior\s*:|-moz-binding/i', $css)) {
            $errs[] = 'CSS: construcciones peligrosas no permitidas.';
        }
        return $errs;
    }

    /** @return list<string> */
    private function checkJs(string $js): array
    {
        $errs = [];
        if (preg_match('/\beval\s*\(|(?<![A-Za-z0-9_$.])(?-i:Function)\s*\(|document\.write|import\s*\(|importScripts|\bfetch\s*\(|XMLHttpRequest|sendBeacon|WebSocket/i', $js)) {
            $errs[] = 'JS: eval, document.write, importación dinámica y peticiones de red no permitidos en diseños.';
        }
        if (preg_match('/https?:\/\//i', $js)) {
            $errs[] = 'JS: no puede contener direcciones remotas.';
        }
        return $errs;
    }
}
