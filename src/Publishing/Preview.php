<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\Menus;
use LuxAeterna\Content\Page;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Content\TypeRegistry;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Database;

/**
 * Previsualización con las MISMAS plantillas y el mismo renderizador que la publicación. Devuelve HTML en memoria:
 * no escribe nada en la versión pública, en sitemaps ni en exportaciones. El panel la sirve con noindex y no-store.
 */
final class Preview
{
    public const CSS_URL = '/admin/preview/style.css';

    public function __construct(private readonly Config $config, private readonly TypeRegistry $types, private readonly Menus $menus, private readonly Database $db, private readonly string $layoutDir, private readonly ?\Closure $design = null)
    {
    }

    /** @return array<string,mixed> ajustes de diseño actuales (los de la próxima publicación) */
    private function designSettings(): array
    {
        return $this->design === null ? [] : ($this->design)();
    }

    private function renderer(): SiteRenderer
    {
        return new SiteRenderer($this->config, $this->layoutDir, $this->designSettings(), true);
    }

    /** CSS de previsualización para un contenido concreto (mismas clases de instancia que la publicación). */
    public function css(string $uid = 'preview', string $type = 'page'): string
    {
        $r = $this->renderer();
        $p = new Page($uid, 'x', 'x', '', [], false, '/x/', $type);
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
        $blocks = array_map(static fn(array $b): array => in_array($b['type'], ['image', 'gallery'], true)
            ? ['type' => 'paragraph', 'text' => '[Aquí irá ' . ($b['type'] === 'image' ? 'la imagen' : 'la galería') . ' «' . $b['ref'] . '» cuando exista la biblioteca multimedia]']
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
        $r = $this->renderer();
        $site = $r->siteVars($snap, $cssUrl . (str_contains($cssUrl, '?') ? '&' : '?') . 'u=' . rawurlencode($uid) . '&t=' . rawurlencode($type), '<meta name="robots" content="noindex,nofollow">');
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
