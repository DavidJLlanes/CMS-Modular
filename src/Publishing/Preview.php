<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\Menus;
use LuxAeterna\Content\Page;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Content\TypeRegistry;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Database;
use LuxAeterna\Media\ImageRenderer;
use LuxAeterna\Media\MediaStore;
use LuxAeterna\Media\Recipe;

/**
 * Previsualización con las MISMAS plantillas y el mismo renderizador que la publicación. Devuelve HTML en memoria:
 * no escribe nada en la versión pública, en sitemaps ni en exportaciones. El panel la sirve con noindex y no-store.
 */
final class Preview
{
    public const CSS_URL = '/admin/preview/style.css';

    public function __construct(private readonly Config $config, private readonly TypeRegistry $types, private readonly Menus $menus, private readonly Database $db, private readonly string $layoutDir, private readonly ?\Closure $design = null, private readonly ?\Closure $media = null)
    {
    }

    /** @return array<string,mixed> ajustes de diseño actuales (los de la próxima publicación) */
    private function designSettings(): array
    {
        return $this->design === null ? [] : ($this->design)();
    }

    private function renderer(array $media = []): SiteRenderer
    {
        return new SiteRenderer($this->config, $this->layoutDir, $this->designSettings(), true, $media);
    }

    private function store(): ?MediaStore
    {
        return $this->media === null ? null : ($this->media)();
    }

    /** @param list<array<string,mixed>> $blocks @return array{items:array<string,array<string,mixed>>,quality:int,max_width:int} imágenes del contenido en edición */
    private function mediaFor(array $blocks): array
    {
        $store = $this->store();
        $s = $store?->settings();
        $items = [];
        \LuxAeterna\Content\Blocks::walk($blocks, static function (array $b) use ($store, &$items): void {
            if ($store !== null && $b['type'] === 'image' && ($m = $store->get((string)$b['ref'])) !== null) {
                $items[(string)$m['uid']] = $m;
            }
        });
        return ['items' => $items, 'quality' => $s?->quality() ?? 82, 'max_width' => $s?->maxWidth() ?? 1600];
    }

    /**
     * Sirve un derivado de imagen a la previsualización: se valida que nombre y clave correspondan a la receta vigente
     * y se construye a demanda. @return array{0:string,1:string}|null [mime, bytes]
     */
    public function image(string $name): ?array
    {
        $store = $this->store();
        if ($store === null || !preg_match('/^(img-[0-9a-f]{12})-(\d{2,5})\.([0-9a-f]{16})\.webp$/', $name, $m)) {
            return null;
        }
        $rec = $store->get($m[1]);
        if ($rec === null) {
            return null;
        }
        $s = $store->settings();
        $r = Recipe::make((int)$m[2], 0, 'inside', 50, 50, $s->quality());
        if (Recipe::key((string)$rec['sha256'], $r) !== $m[3]) {
            $r = $store->thumbRecipe(); // miniatura de la biblioteca
            if ((int)$m[2] !== MediaStore::THUMB || Recipe::key((string)$rec['sha256'], $r) !== $m[3]) {
                return null;
            }
        }
        $d = $store->ensure($rec, [$r])[$m[3]] ?? null;
        return $d === null ? null : ['image/webp', (string)file_get_contents($d['file'])];
    }

    /** CSS de previsualización para un contenido concreto (mismas clases de instancia que la publicación). */
    public function css(string $uid = 'preview', string $type = 'page', array $colors = []): string
    {
        $r = $this->renderer();
        // Los colores libres del contenido necesitan su clase CSS: se pasan como un bloque sintético (solo en la vista previa).
        $runs = array_map(static fn(string $c): array => ['t' => 'x', 'c' => $c], $colors);
        $p = new Page($uid, 'x', 'x', '', $runs === [] ? [] : [['type' => 'paragraph', 'inlines' => $runs]], false, '/x/', $type);
        $r->siteVars(new SiteSnapshot([$p], []), self::CSS_URL);
        return $r->design->previewCss();
    }

    /** Fuente o logo del diseño para la previsualización (solo archivos del catálogo local). @return array{0:string,1:string}|null [mime, bytes] */
    public function asset(string $kind, string $name, string $file = ''): ?array
    {
        $r = $this->renderer();
        if ($kind === 'font') {
            $f = $r->design->fonts->get($name);
            foreach ((array)($f['variants'] ?? []) as $v) {
                if ($v['file'] === $file) {
                    return ['font/woff2', (string)file_get_contents($this->layoutDir . '/../../fonts/' . $name . '/' . $file)];
                }
            }
            return null;
        }
        $i = \LuxAeterna\Design\Logo::inspect($this->layoutDir . '/../../logos', $name);
        return $i === null ? null : [$i['mime'], (string)file_get_contents($this->layoutDir . '/../../logos/' . $name)];
    }

    /** @param array<string,mixed> $rev revisión decodificada (title, slug, summary, fields, blocks) */
    public function html(string $type, array $rev, string $cssUrl = self::CSS_URL, string $uid = 'preview'): string
    {
        $blocks = array_map(static fn(array $b): array => $b['type'] === 'gallery'
            ? ['type' => 'paragraph', 'text' => '[Aquí irá la galería «' . $b['ref'] . '» cuando exista el módulo de galerías (Fase 8)]']
            : $b, (array)$rev['blocks']);
        $fields = [];
        foreach ($this->types->fields($type) as $name => $def) {
            $v = $rev['fields'][$name] ?? '';
            if ($v !== '' && $v !== null) {
                $fields[] = ['label' => (string)$def['label'], 'value' => is_bool($v) ? ($v ? 'Sí' : 'No') : (string)$v];
            }
        }
        $page = new Page($uid, (string)$rev['slug'], (string)$rev['title'] !== '' ? (string)$rev['title'] : '(sin título)', (string)$rev['summary'], $blocks, false,
            $this->types->path($type, (string)$rev['slug']), $type, time(), $fields);
        $snap = new SiteSnapshot([$page], ['main' => $this->links('main'), 'footer' => $this->links('footer')]);
        $colors = \LuxAeterna\Content\Blocks::colors($blocks);
        $r = $this->renderer($this->mediaFor($blocks));
        $site = $r->siteVars($snap, $cssUrl . (str_contains($cssUrl, '?') ? '&' : '?') . 'u=' . rawurlencode($uid) . '&t=' . rawurlencode($type)
            . ($colors !== [] ? '&c=' . implode(',', array_map(static fn(string $c): string => substr($c, 1), $colors)) : ''), '<meta name="robots" content="noindex,nofollow">');
        return $r->page($page, $site);
    }

    /** @return list<array{label:string,url:string}> enlaces del menú (incluye contenido aún no publicado: es una vista previa) */
    private function links(string $menu): array
    {
        $out = [];
        foreach ($this->menus->items($menu) as $it) {
            if ($it['target_uid'] !== null) {
                $c = $this->db->one('SELECT type, slug FROM contents WHERE uid = ? AND status <> \'trashed\'', [$it['target_uid']]);
                if ($c !== null) {
                    $out[] = ['label' => (string)$it['label'], 'url' => '#'];
                }
            } else {
                $out[] = ['label' => (string)$it['label'], 'url' => '#'];
            }
        }
        return $out;
    }
}
