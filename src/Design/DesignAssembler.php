<?php
declare(strict_types=1);

namespace LuxAeterna\Design;

use LuxAeterna\Content\Page;
use LuxAeterna\Core\Config;
use LuxAeterna\Publishing\BlockRenderer;
use LuxAeterna\Publishing\Template;

/**
 * Une ajustes de diseño, tema, fuentes y logo en el HTML y los recursos de cada página.
 *  - Un CSS «core» (tema + base + instancias) y un CSS/JS por componente con huella de contenido: se deduplican
 *    solos porque dos páginas con el mismo componente enlazan el mismo archivo.
 *  - Solo se publican las fuentes realmente usadas por el tema, desde el propio dominio.
 *  - El inventario lista, por página, los recursos que realmente intervienen.
 * En modo previsualización las URL apuntan a rutas privadas del panel y nada se escribe en la versión pública.
 */
final class DesignAssembler
{
    public readonly DesignRegistry $registry;
    public readonly FontCatalog $fonts;
    private DesignSettings $settings;
    /** @var array<string,mixed> */
    private array $theme;
    /** @var array<string,mixed> */
    private array $logo;
    /** @var array<string,array{content?:string,file?:string}> */
    private array $assets = [];
    /** @var array<string,string> clase de instancia → reglas CSS */
    private array $instanceCss = [];
    /** @var array<string,array<string,mixed>> */
    private array $perPage = [];
    /** @var list<string> */
    private array $warnings = [];
    private string $coreUrl = '';
    private string $coreCss = '';
    /** @var array<string,string> */
    private array $fontUrls = [];
    private ?string $logoUrl = null;
    private bool $planned = false;

    /** @param array<string,mixed> $raw ajustes de diseño del snapshot */
    public function __construct(private readonly Config $config, private readonly string $componentsDir, array $raw, private readonly bool $preview = false)
    {
        $cache = null;
        try {
            $cache = $config->path('data') . '/cache/designs.json';
        } catch (\Throwable) {
        }
        $this->registry = new DesignRegistry($componentsDir, $cache, \LuxAeterna\Core\Version::CURRENT);
        $this->fonts = new FontCatalog($componentsDir . '/fonts');
        $this->settings = new DesignSettings($raw, $this->registry);
        try {
            $this->theme = Theme::normalize((array)($raw['theme'] ?? []), $this->fonts);
        } catch (\InvalidArgumentException $e) {
            $this->warnings[] = 'Tema no válido (' . $e->getMessage() . '): se usa el tema por defecto.';
            $this->theme = Theme::defaults();
        }
        try {
            $this->logo = Logo::normalize((array)($raw['logo'] ?? []), $componentsDir . '/logos');
        } catch (\InvalidArgumentException $e) {
            $this->warnings[] = 'Logo no válido (' . $e->getMessage() . '): se usa el nombre del sitio.';
            $this->logo = Logo::defaults();
        }
        foreach ($this->registry->index()['errors'] as $e) {
            $this->warnings[] = "Diseño no válido en {$e['dir']}: {$e['message']}";
        }
        foreach ($this->settings->problems() as $p) {
            $this->warnings[] = "Ajuste de diseño: $p";
        }
    }

    /**
     * Resuelve todas las ranuras de todas las páginas y construye los recursos. Debe llamarse antes de pageVars().
     * @param list<Page> $pages @param array<string,string> $site variables comunes (site_name, nav, footer_nav, lang…)
     */
    public function plan(array $pages, array $site, string $previewCssUrl = '', array $galleries = []): void
    {
        $this->planned = true;
        $this->assets = [];
        $this->perPage = [];
        $this->instanceCss = [];
        $this->contentColors = [];
        foreach ($pages as $pg) {
            foreach (\LuxAeterna\Content\Blocks::colors($pg->blocks) as $col) {
                $this->contentColors[$col] = true;
            }
        }
        $this->prepareFontsAndLogo();
        $componentFiles = [];
        foreach ($pages as $p) {
            $path = $p->path();
            $slots = [];
            $used = [];
            foreach (DesignSettings::SLOTS as $slot) {
                $r = $this->settings->resolve($slot, $p->type, $p->id);
                if ($r === null) {
                    $slots[$slot] = '';
                    continue;
                }
                $d = $r['design'];
                $html = $this->renderComponent($d, $r['values'], $slot, $p, $site);
                $cls = $this->instanceClass($d, $r['values']);
                $slots[$slot] = '<div class="lux-slot lux-slot--' . $slot . ($cls !== '' ? ' ' . $cls : '') . '">' . trim($html) . '</div>' . "\n";
                $used[] = $d['id'];
                $componentFiles[$d['id']] ??= $this->componentAssets($d);
            }
            foreach ($this->galleryComponents($p, $galleries) as $gid) {
                if (!in_array($gid, $used, true)) {
                    $used[] = $gid;
                    $componentFiles[$gid] ??= $this->componentAssets($this->registry->get($gid) ?? []);
                }
            }
            $this->perPage[$path] = [
                'slots' => $slots,
                'used' => $used,
                'heading' => $slots['hero'] === '' ? '<h1>' . BlockRenderer::e($p->title) . "</h1>\n" : '',
            ];
        }
        $this->buildCore();
        $this->coreCss = $this->assets[$this->coreUrl]['content'] ?? '';
        foreach ($this->perPage as $path => &$pp) {
            $res = [$this->coreUrl];
            foreach ($pp['used'] as $id) {
                array_push($res, ...array_keys($componentFiles[$id]));
            }
            $pp['resources'] = array_values(array_unique(array_merge($res, array_values($this->fontUrls), $this->logoUrl !== null ? [$this->logoUrl] : [])));
            $pp['components'] = $pp['used'];
        }
        unset($pp);
        foreach ($componentFiles as $files) {
            foreach ($files as $url => $a) {
                $this->assets[$url] = $a;
            }
        }
        if ($this->preview) {
            $this->previewCssUrl = $previewCssUrl;
        }
    }

    private string $previewCssUrl = '';

    /**
     * Diseños de galería y visor que necesita una página (solo si la usan: una página sin galería no carga nada del visor).
     * De paso fija la clase de instancia, que debe existir antes de construir el CSS núcleo.
     * @param array<string,array<string,mixed>> $galleries @return list<string>
     */
    private function galleryComponents(Page $p, array $galleries): array
    {
        $ids = [];
        \LuxAeterna\Content\Blocks::walk($p->blocks, function (array $b) use ($galleries, &$ids): void {
            $g = $b['type'] === 'gallery' ? ($galleries[(string)$b['ref']] ?? null) : null;
            $d = $g === null ? null : $this->registry->get((string)$g['layout']);
            if ($d === null || $d['type'] !== 'gallery' || $g['items'] === []) {
                return;
            }
            $ids[] = $d['id'];
            $this->galleryClass($d, array_replace($d['defaults'], array_intersect_key((array)$g['options'], $d['fields'])));
            $v = $this->registry->get(\LuxAeterna\Gallery\GalleryRenderer::VIEWER);
            if (!empty($g['lightbox']['enabled']) && $v !== null && $v['type'] === 'viewer') {
                $ids[] = $v['id'];
            }
        });
        return array_values(array_unique($ids));
    }

    /** @param array<string,mixed> $d @param array<string,mixed> $values */
    public function galleryClass(array $d, array $values): string
    {
        return $this->instanceClass($d, $values);
    }

    /** Añade una página generada fuera del snapshot (404) reutilizando los recursos ya planificados. @param array<string,string> $site */
    public function planExtra(Page $p, array $site): void
    {
        $slots = [];
        $used = [];
        foreach (DesignSettings::SLOTS as $slot) {
            $res = $slot === 'hero' ? null : $this->settings->resolve($slot, $p->type, $p->id);
            if ($res === null) {
                $slots[$slot] = '';
                continue;
            }
            $cls = $this->instanceClass($res['design'], $res['values']);
            $slots[$slot] = '<div class="lux-slot lux-slot--' . $slot . ($cls !== '' ? ' ' . $cls : '') . '">' . trim($this->renderComponent($res['design'], $res['values'], $slot, $p, $site)) . '</div>' . "\n";
            $used[] = $res['design']['id'];
            foreach ($this->componentAssets($res['design']) as $u => $a) {
                $this->assets[$u] ??= $a;
            }
        }
        $this->perPage[$p->path()] = ['slots' => $slots, 'used' => $used, 'heading' => '<h1>' . BlockRenderer::e($p->title) . "</h1>\n", 'components' => $used,
            'resources' => $this->resourcesFor($used)];
    }

    /** @return array<string,string> variables de ranura y recursos para una página ya planificada */
    public function pageVars(Page $p): array
    {
        $pp = $this->perPage[$p->path()] ?? ['slots' => array_fill_keys(DesignSettings::SLOTS, ''), 'used' => [], 'heading' => '<h1>' . BlockRenderer::e($p->title) . "</h1>\n"];
        return [
            'topbar' => $pp['slots']['topbar'], 'header' => $pp['slots']['header'], 'hero' => $pp['slots']['hero'], 'footer' => $pp['slots']['footer'],
            'page_heading' => $pp['heading'], 'head_assets' => $this->headAssets($pp['used']),
        ];
    }

    /** @param list<string> $used @return list<string> */
    private function resourcesFor(array $used): array
    {
        $res = [$this->coreUrl];
        foreach ($used as $id) {
            array_push($res, ...$this->assetUrlsOf($id));
        }
        array_push($res, ...array_values(array_filter($this->fontUrls, static fn($k) => $k !== 'preload', ARRAY_FILTER_USE_KEY)));
        if ($this->logoUrl !== null) {
            $res[] = $this->logoUrl;
        }
        return array_values(array_unique($res));
    }

    /** @param list<string> $used */
    private function headAssets(array $used): string
    {
        if ($this->preview) {
            return '<link rel="stylesheet" href="' . BlockRenderer::e($this->previewCssUrl) . '">';
        }
        $h = '';
        foreach ($this->fontUrls as $key => $url) {
            if ($key === 'preload') {
                $h .= '<link rel="preload" href="' . $url . '" as="font" type="font/woff2" crossorigin>' . "\n";
            }
        }
        $h .= '<link rel="stylesheet" href="' . $this->coreUrl . '">' . "\n";
        $seen = [];
        foreach ($used as $id) {
            foreach ($this->assetUrlsOf($id) as $u) {
                if (!isset($seen[$u])) {
                    $seen[$u] = true;
                    $h .= str_ends_with($u, '.css') ? '<link rel="stylesheet" href="' . $u . '">' . "\n" : '<script src="' . $u . '" defer></script>' . "\n";
                }
            }
        }
        return $h;
    }

    /** @return list<string> */
    private function assetUrlsOf(string $id): array
    {
        $d = $this->registry->get($id);
        if ($d === null) {
            return [];
        }
        $u = ['/assets/c/' . $id . '.' . $d['hash'] . '.css'];
        if (isset($d['files']['js'])) {
            $u[] = '/assets/c/' . $id . '.' . $d['hash'] . '.js';
        }
        return $u;
    }

    /** @return array<string,array{content?:string,file?:string}> ruta pública → contenido o archivo de origen */
    public function assets(): array
    {
        return $this->assets;
    }

    /** CSS completo para previsualización: núcleo + todos los componentes disponibles. */
    public function previewCss(): string
    {
        $css = $this->coreCss;
        foreach ($this->registry->index()['designs'] as $d) {
            $css .= "\n" . (string)@file_get_contents($this->componentsDir . '/' . $d['dir'] . '/' . $d['files']['css']);
        }
        return $css;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string,mixed> inventario por página y módulos compartidos */
    public function inventory(): array
    {
        $pages = [];
        $count = [];
        foreach ($this->perPage as $path => $pp) {
            $pages[$path] = ['components' => $pp['components'], 'resources' => $pp['resources']];
            foreach ($pp['resources'] as $r) {
                $count[$r] = ($count[$r] ?? 0) + 1;
            }
        }
        $n = count($pages);
        $shared = array_keys(array_filter($count, static fn(int $c): bool => $c === $n && $n > 1));
        $modules = [];
        foreach ($pages as $pg) {
            foreach ($pg['components'] as $id) {
                $d = $this->registry->get($id);
                $modules[$id] = ['version' => $d['version'] ?? '?', 'type' => $d['type'] ?? '?', 'resources' => $this->assetUrlsOf($id)];
            }
        }
        ksort($modules);
        ksort($pages);
        return ['schema' => 1, 'pages' => $pages, 'shared' => $shared, 'modules' => $modules, 'fonts' => array_values(array_filter($this->fontUrls, static fn($k) => $k !== 'preload', ARRAY_FILTER_USE_KEY)), 'logo' => $this->logoUrl];
    }

    public function theme(): array
    {
        return $this->theme;
    }

    /* ---------------- internos ---------------- */

    /** @param array<string,mixed> $d @param array<string,mixed> $values @param array<string,string> $site */
    private function renderComponent(array $d, array $values, string $slot, Page $p, array $site): string
    {
        $tpl = (string)file_get_contents($this->componentsDir . '/' . $d['dir'] . '/' . $d['files']['template']);
        $vars = [];
        foreach ($values as $k => $v) {
            $vars[$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        }
        $vars += [
            'site_name' => $site['site_name'] ?? '', 'home_url' => '/', 'title' => $p->title, 'description' => $p->description, 'year' => gmdate('Y'),
            'nav' => $site['nav'] ?? '', 'footer_nav' => $site['footer_nav'] ?? '', 'logo' => $this->logoHtml($slot, $site['site_name'] ?? ''),
        ];
        return Template::render($tpl, $vars);
    }

    private function logoHtml(string $place, string $siteName): string
    {
        $l = $this->logo;
        if ($l['mode'] === 'image' && $this->logoUrl !== null) {
            $i = Logo::inspect($this->componentsDir . '/logos', $l['image']);
            if ($i !== null) {
                [$w] = $l['sizes'][$place] ?? [160, 120];
                $h = max(1, (int)round($w * $i['h'] / $i['w']));
                return '<img class="lux-logo lux-logo--' . $place . '" src="' . BlockRenderer::e($this->logoUrl) . '" width="' . $w . '" height="' . $h . '" alt="' . BlockRenderer::e($l['alt'] !== '' ? $l['alt'] : $siteName) . '">';
            }
        }
        return '<span class="lux-logo-text">' . BlockRenderer::e($l['text'] !== '' ? $l['text'] : $siteName) . '</span>';
    }

    /** @param array<string,mixed> $d @param array<string,mixed> $values */
    private function instanceClass(array $d, array $values): string
    {
        $vars = [];
        foreach ($d['fields'] as $name => $def) {
            if (in_array($def['type'], ['color', 'number'], true)) {
                $vars[] = '--lux-f-' . str_replace('_', '-', $name) . ':' . $values[$name];
            }
        }
        if ($vars === []) {
            return '';
        }
        $cls = 'lux-i-' . substr(hash('sha256', $d['id'] . implode(';', $vars)), 0, 8);
        $this->instanceCss[$cls] = '.' . $cls . '{' . implode(';', $vars) . '}';
        return $cls;
    }

    /** @param array<string,mixed> $d @return array<string,array{content?:string,file?:string}> */
    private function componentAssets(array $d): array
    {
        $base = $this->componentsDir . '/' . $d['dir'];
        $out = ['/assets/c/' . $d['id'] . '.' . $d['hash'] . '.css' => ['file' => $base . '/' . $d['files']['css']]];
        if (isset($d['files']['js'])) {
            $out['/assets/c/' . $d['id'] . '.' . $d['hash'] . '.js'] = ['file' => $base . '/' . $d['files']['js']];
        }
        return $out;
    }

    private function prepareFontsAndLogo(): void
    {
        $this->fontUrls = [];
        $this->logoUrl = null;
        $t = $this->theme['typography'];
        $uses = [];
        $body = $t['body']['family'];
        $head = $t['heading']['family'];
        if ($body !== 'system') {
            $uses[] = ['family' => $body, 'weights' => [400, 700], 'styles' => ['normal', 'italic']];
        }
        if ($head !== 'system') {
            $uses[] = ['family' => $head, 'weights' => [$t['heading']['weight']], 'styles' => ['normal']];
        }
        $langs = [(string)$this->config->get('site.lang', 'es')];
        foreach (array_unique([$body, $head]) as $f) {
            if ($f !== 'system' && ($m = $this->fonts->missingChars($f, $langs)) !== []) {
                $this->warnings[] = 'La fuente «' . ($this->fonts->get($f)['family'] ?? $f) . '» no cubre estos caracteres: ' . implode(' ', $m);
            }
        }
        $faces = '';
        foreach ($this->fonts->used($uses) as $v) {
            $hash = substr(hash_file('sha256', $v['path']), 0, 10);
            $url = $this->preview ? '/admin/preview/font/' . $v['slug'] . '/' . $v['file'] : '/assets/fonts/' . $v['slug'] . '-' . $v['weight'] . '-' . $v['style'] . '.' . $hash . '.woff2';
            if (!$this->preview) {
                $this->assets[$url] = ['file' => $v['path']];
            }
            $this->fontUrls[$v['slug'] . $v['weight'] . $v['style']] = $url;
            if ($v['slug'] === $body && $v['weight'] === 400 && $v['style'] === 'normal') {
                $this->fontUrls['preload'] = $url;
            }
            $faces .= '@font-face{font-family:"' . $v['family'] . '";font-style:' . $v['style'] . ';font-weight:' . $v['weight'] . ';font-display:swap;src:url(' . $url . ') format("woff2");unicode-range:' . $v['range'] . "}\n";
        }
        $this->faces = $faces;
        if ($this->logo['mode'] === 'image' || $this->logo['image'] !== '') {
            $i = Logo::inspect($this->componentsDir . '/logos', $this->logo['image']);
            if ($i !== null) {
                $this->logoUrl = $this->preview ? '/admin/preview/logo/' . $this->logo['image'] : '/assets/logo.' . $i['hash'] . '.' . $i['ext'];
                if (!$this->preview) {
                    $this->assets[$this->logoUrl] = ['file' => $this->componentsDir . '/logos/' . $this->logo['image']];
                }
            }
        }
    }

    private string $faces = '';
    /** @var array<string,true> colores #rrggbb que usan los contenidos (una clase CSS por cada uno) */
    private array $contentColors = [];

    private function buildCore(): void
    {
        $t = $this->theme['typography'];
        $stacks = ['body' => $this->fonts->stack($t['body']['family']), 'heading' => $this->fonts->stack($t['heading']['family'])];
        $css = $this->faces . Theme::css($this->theme, $stacks);
        $css .= (string)file_get_contents($this->componentsDir . '/layouts/base/style.css') . "\n";
        foreach ($this->logo['sizes'] as $place => [$d, $m]) {
            $css .= ".lux-logo--$place{width:{$d}px;height:auto;max-width:100%}\n";
            $css .= "@media (max-width:640px){.lux-logo--$place{width:{$m}px}}\n";
        }
        ksort($this->contentColors);
        foreach (array_keys($this->contentColors) as $col) {
            $css .= '.' . \LuxAeterna\Content\Inline::colorClass((string)$col) . '{color:' . $col . "}\n";
        }
        ksort($this->instanceCss);
        $css .= implode("\n", $this->instanceCss) . "\n";
        $this->coreUrl = '/assets/core.' . substr(hash('sha256', $css), 0, 10) . '.css';
        $this->assets[$this->coreUrl] = ['content' => $css];
    }
}
