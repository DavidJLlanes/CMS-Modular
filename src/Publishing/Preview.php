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
    private ?SiteRenderer $renderer = null;

    public function __construct(private readonly Config $config, private readonly TypeRegistry $types, private readonly Menus $menus, private readonly Database $db, private readonly string $layoutDir)
    {
    }

    private function renderer(): SiteRenderer
    {
        return $this->renderer ??= new SiteRenderer($this->config, $this->layoutDir);
    }

    public function css(): string
    {
        return $this->renderer()->css;
    }

    /** @param array<string,mixed> $rev revisión decodificada (title, slug, summary, fields, blocks) */
    public function html(string $type, array $rev, string $cssUrl = self::CSS_URL): string
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
        $page = new Page('preview', (string)$rev['slug'], (string)$rev['title'] !== '' ? (string)$rev['title'] : '(sin título)', (string)$rev['summary'], $blocks, false,
            $this->types->path($type, (string)$rev['slug']), $type, time(), $fields);
        $snap = new SiteSnapshot([$page], ['main' => $this->links('main'), 'footer' => $this->links('footer')]);
        $site = $this->renderer()->siteVars($snap, $cssUrl, '<meta name="robots" content="noindex,nofollow">');
        return $this->renderer()->page($page, $site);
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
