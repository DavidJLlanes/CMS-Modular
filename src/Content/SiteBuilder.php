<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

use LuxAeterna\Core\Database;
use LuxAeterna\Core\LuxException;

/**
 * Construye la instantánea publicable SOLO con revisiones publicadas. Los borradores, las revisiones en edición,
 * la papelera y las previsualizaciones no pueden llegar a la salida: no se leen aquí.
 */
final class SiteBuilder
{
    public function __construct(
        private readonly Database $db,
        private readonly TypeRegistry $types,
        private readonly Menus $menus,
        private readonly Settings $settings,
        /** @var ?\Closure():array{quality:int,max_width:int} ajustes de procesado de imágenes */
        private readonly ?\Closure $mediaSettings = null,
        /** @var ?\Closure():\LuxAeterna\Gallery\GalleryStore galerías (Fase 8) */
        private readonly ?\Closure $galleries = null,
    ) {
    }

    public function hasPublished(): bool
    {
        return (int)$this->db->val("SELECT COUNT(*) FROM contents WHERE status = 'published'") > 0;
    }

    public function build(): SiteSnapshot
    {
        $rows = $this->db->all("SELECT c.id, c.uid, c.type, c.published_at, r.title, r.slug, r.summary, r.fields, r.blocks
                                FROM contents c JOIN revisions r ON r.id = c.published_rev WHERE c.status = 'published' ORDER BY c.published_at DESC, c.id DESC");
        $home = $this->settings->get('home_uid');
        $homeRow = null;
        foreach ($rows as $r) {
            if ($r['uid'] === $home && $r['type'] === 'page') {
                $homeRow = $r;
            }
        }
        if ($homeRow === null) {
            throw new LuxException('Falta la portada: elige una página publicada como portada en Ajustes del sitio.');
        }
        $termsByContent = $this->termsByContent();
        $pages = [];
        $uses = [];
        $noAlt = [];
        $galUses = [];
        $pageWarnings = [];
        $paths = [];
        $uidPath = [];
        $redirects = [];
        foreach ($rows as $r) {
            $isHome = $r === $homeRow;
            $path = $isHome ? '/' : $this->types->path((string)$r['type'], (string)$r['slug']);
            if (isset($paths[$path])) {
                throw new LuxException("Colisión de rutas en la publicación: $path.");
            }
            $paths[$path] = true;
            $uidPath[(string)$r['uid']] = $path;
            if ($isHome) {
                $redirects[] = ['from' => $this->types->path('page', (string)$r['slug']), 'to' => '/'];
            }
            $blocks = Blocks::validate(json_decode((string)$r['blocks'], true) ?: []);
            Blocks::walk($blocks, function (array $b) use ($r, &$uses, &$noAlt, &$galUses): void {
                if ($b['type'] === 'gallery') {
                    $galUses[(string)$b['ref']][] = (string)$r['title'];
                }
                if ($b['type'] === 'image') {
                    $uses[(string)$b['ref']][] = (string)$r['title'];
                    if (trim((string)$b['alt']) === '') {
                        $noAlt[(string)$b['ref']][] = (string)$r['title'];
                    }
                }
            });
            foreach (self::editorialWarnings((string)$r['title'], $blocks) as $w) {
                $pageWarnings[] = $w;
            }
            $pages[] = new Page(
                (string)$r['uid'], $isHome ? '' : (string)$r['slug'], (string)$r['title'], (string)$r['summary'], $blocks, false,
                $path, (string)$r['type'], (int)$r['published_at'], $this->displayFields((string)$r['type'], json_decode((string)$r['fields'], true) ?: []),
                $termsByContent[(int)$r['id']]['names'] ?? []
            );
        }
        array_push($pages, ...$this->listings($rows, $termsByContent, $paths, (string)$homeRow['uid']));
        $warnings = $pageWarnings;
        $menus = [];
        foreach (array_keys(Menus::MENUS) as $m) {
            $menus[$m] = $this->resolveMenu($m, $uidPath, $warnings);
        }
        foreach ($this->db->all('SELECT from_path, to_path FROM redirects ORDER BY from_path') as $rd) {
            $redirects[] = ['from' => (string)$rd['from_path'], 'to' => (string)$rd['to_path']];
        }
        $final = [];
        foreach ($redirects as $rd) {
            if (isset($paths[$rd['from']])) {
                continue; // la ruta ya existe como página real
            }
            if (!isset($paths[$rd['to']]) && !$this->isGenerated($pages, $rd['to'])) {
                $warnings[] = "Redirección ignorada: {$rd['from']} apunta a {$rd['to']}, que no está publicada.";
                continue;
            }
            $final[$rd['from']] = $rd;
        }
        $design = json_decode((string)$this->settings->get('design', '{}'), true);
        $media = $this->mediaFor($uses, $noAlt, $warnings, $galUses);
        return new SiteSnapshot($pages, $menus, array_values($final), $warnings, is_array($design) ? $design : [], $media);
    }

    /**
     * Imágenes usadas por las páginas publicadas, congeladas con el resto del snapshot. Una referencia rota bloquea.
     * @param array<string,list<string>> $uses ref → títulos @param array<string,list<string>> $noAlt @param list<string> $warnings
     * @return array{items:array<string,array<string,mixed>>,quality:int,max_width:int}
     */
    private function mediaFor(array $uses, array $noAlt, array &$warnings, array $galUses = []): array
    {
        $items = [];
        $galleries = [];
        if ($galUses !== []) {
            if ($this->galleries === null) {
                throw new LuxException('Hay galerías en el contenido pero el módulo de galerías no está disponible.');
            }
            $snap = ($this->galleries)()->snapshot(array_keys($galUses));
            foreach ($galUses as $uid => $titles) {
                $g = $snap['galleries'][$uid] ?? throw new LuxException('«' . $titles[0] . '» usa una galería que ya no existe (' . $uid . '). Quita el bloque o elige otra galería.');
                if ($g['dangling'] > 0) {
                    throw new LuxException('La galería «' . ($g['title'] !== '' ? $g['title'] : $uid) . '» (usada en «' . $titles[0] . '») contiene ' . $g['dangling'] . ' foto(s) que ya no están en la biblioteca. Quítalas de la galería.');
                }
                if ($g['items'] === []) {
                    $warnings[] = 'La galería «' . ($g['title'] !== '' ? $g['title'] : $uid) . '» está vacía y no mostrará nada en «' . $titles[0] . '».';
                }
                $missing = count(array_filter($g['items'], static fn(array $i): bool => $i['missing_alt']));
                if ($missing > 0) {
                    $warnings[] = 'La galería «' . ($g['title'] !== '' ? $g['title'] : $uid) . '» tiene ' . $missing . ' foto(s) sin texto alternativo.';
                }
                $galleries[$uid] = $g;
            }
            $items = $snap['media'];
        }
        if ($uses !== []) {
            $marks = implode(',', array_fill(0, count($uses), '?'));
            foreach ($this->db->all("SELECT * FROM media WHERE uid IN ($marks)", array_keys($uses)) as $m) {
                $items[(string)$m['uid']] = $m;
            }
        }
        foreach ($uses as $ref => $titles) {
            if (!isset($items[$ref])) {
                throw new LuxException('«' . $titles[0] . '» usa una imagen que ya no existe en la biblioteca (' . $ref . '). Sustitúyela o quítala.');
            }
        }
        foreach ($noAlt as $ref => $titles) {
            if (trim((string)$items[$ref]['alt']) === '') {
                $warnings[] = 'Imagen sin texto alternativo en «' . $titles[0] . '»' . (count($titles) > 1 ? ' y ' . (count($titles) - 1) . ' más' : '') . ' (' . $ref . ').';
            }
        }
        $s = $this->mediaSettings === null ? ['quality' => 82, 'max_width' => 1600] : ($this->mediaSettings)();
        return ['items' => $items, 'galleries' => $galleries, 'quality' => (int)$s['quality'], 'max_width' => (int)$s['max_width']];
    }

    /**
     * Avisos editoriales de un contenido (no bloquean): el título ya sale como H1 en la plantilla, saltos de nivel, enlaces y encabezados vacíos…
     * @param list<array<string,mixed>> $blocks @return list<string>
     */
    public static function editorialWarnings(string $title, array $blocks): array
    {
        $w = [];
        $prev = 1; // el H1 lo escribe la plantilla con el título de la página
        Blocks::walk($blocks, static function (array $b) use (&$w, &$prev, $title): void {
            if ($b['type'] === 'heading') {
                $text = trim(isset($b['inlines']) ? Inline::plain($b['inlines']) : (string)($b['text'] ?? ''));
                if ($b['level'] === 1) {
                    $w[] = "«{$title}» tiene un título de nivel 1 en el contenido y la plantilla ya muestra el título de la página como H1: usa nivel 2 o revisa el SEO.";
                } elseif ($b['level'] > $prev + 1) {
                    $w[] = "«{$title}» salta de un título de nivel $prev a uno de nivel {$b['level']}.";
                }
                if ($text === '') {
                    $w[] = "«{$title}» tiene un título vacío.";
                }
                $prev = (int)$b['level'];
            }
            if ($b['type'] === 'table' && !$b['header_row'] && !$b['header_col']) {
                $w[] = "«{$title}» tiene una tabla sin fila ni columna de encabezados.";
            }
        });
        return array_values(array_unique($w));
    }

    /** @param list<Page> $pages */
    private function isGenerated(array $pages, string $path): bool
    {
        foreach ($pages as $p) {
            if ($p->path() === $path) {
                return true;
            }
        }
        return false;
    }

    /**
     * Listados generados: blog, tipos propios, categorías y etiquetas.
     * @param list<array<string,mixed>> $rows @param array<int,array{names:list<string>,terms:list<array<string,string>>}> $termsByContent
     * @param array<string,true> $paths @return list<Page>
     */
    private function listings(array $rows, array $termsByContent, array &$paths, string $homeUid): array
    {
        $out = [];
        $add = function (string $path, string $id, string $title, array $items) use (&$out, &$paths): void {
            if ($items === [] || isset($paths[$path])) {
                return;
            }
            $paths[$path] = true;
            $out[] = new Page($id, '', $title, '', [['type' => 'links', 'items' => $items]], false, $path, 'listing');
        };
        $byType = [];
        foreach ($rows as $r) {
            if ($r['type'] !== 'page') {
                $byType[(string)$r['type']][] = $r;
            }
        }
        foreach ($byType as $type => $list) {
            $title = $type === 'post' ? ($this->settings->get('blog_title') ?: 'Blog') : $this->types->label($type);
            $add($this->types->path($type, ''), 'listing-' . $type, $title, $this->items($list, $type));
        }
        $terms = [];
        foreach ($rows as $r) {
            foreach ($termsByContent[(int)$r['id']]['terms'] ?? [] as $t) {
                if ($r['type'] !== 'page') {
                    $terms[$t['taxonomy']][$t['slug']]['name'] = $t['name'];
                    $terms[$t['taxonomy']][$t['slug']]['rows'][] = $r;
                }
            }
        }
        foreach ($terms as $taxonomy => $bySlug) {
            foreach ($bySlug as $slug => $t) {
                $prefix = $taxonomy === 'category' ? 'categoria' : 'etiqueta';
                $label = $taxonomy === 'category' ? 'Categoría' : 'Etiqueta';
                $add("/$prefix/$slug/", "term-$taxonomy-$slug", "$label: {$t['name']}", $this->items($t['rows'], null));
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $list @return list<array{text:string,url:string,note:string}> */
    private function items(array $list, ?string $type): array
    {
        $items = [];
        foreach ($list as $r) {
            $items[] = [
                'text' => (string)$r['title'],
                'url' => $r['uid'] === $this->settings->get('home_uid') ? '/' : $this->types->path((string)$r['type'], (string)$r['slug']),
                'note' => gmdate('d/m/Y', (int)$r['published_at']) . ((string)$r['summary'] !== '' ? ' · ' . $r['summary'] : ''),
            ];
        }
        return $items;
    }

    /** @return array<int,array{names:list<string>,terms:list<array<string,string>>}> */
    private function termsByContent(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT ct.content_id, t.taxonomy, t.slug, t.name FROM content_terms ct JOIN terms t ON t.id = ct.term_id ORDER BY t.taxonomy, t.name') as $r) {
            $out[(int)$r['content_id']]['names'][] = (string)$r['name'];
            $out[(int)$r['content_id']]['terms'][] = ['taxonomy' => (string)$r['taxonomy'], 'slug' => (string)$r['slug'], 'name' => (string)$r['name']];
        }
        return $out;
    }

    /** @param array<string,mixed> $values @return list<array{label:string,value:string}> */
    private function displayFields(string $type, array $values): array
    {
        $out = [];
        foreach ($this->types->fields($type) as $name => $def) {
            if (($def['public'] ?? true) === false || !array_key_exists($name, $values) || $values[$name] === '' || $values[$name] === null) {
                continue;
            }
            $v = $values[$name];
            $out[] = ['label' => (string)$def['label'], 'value' => is_bool($v) ? ($v ? 'Sí' : 'No') : (string)$v];
        }
        return $out;
    }

    /**
     * @param array<string,int> $uidPath
     * @param list<string> $warnings
     * @return list<array{label:string,url:string}>
     */
    private function resolveMenu(string $menu, array $uidPath, array &$warnings): array
    {
        $out = [];
        foreach ($this->menus->items($menu) as $it) {
            if ($it['target_uid'] !== null) {
                if (!isset($uidPath[(string)$it['target_uid']])) {
                    $warnings[] = "Menú «{$it['label']}»: el contenido enlazado no está publicado; se omite.";
                    continue;
                }
                $out[] = ['label' => (string)$it['label'], 'url' => $uidPath[(string)$it['target_uid']]];
            } else {
                $out[] = ['label' => (string)$it['label'], 'url' => (string)$it['url']];
            }
        }
        return $out;
    }
}
