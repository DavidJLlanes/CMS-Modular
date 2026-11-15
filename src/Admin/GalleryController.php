<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

use LuxAeterna\Core\LuxException;
use LuxAeterna\Gallery\GalleryRenderer;
use LuxAeterna\Gallery\GalleryStore;
use LuxAeterna\Media\ImageRenderer;
use LuxAeterna\Security\Authorizer;

/** Galerías del panel: crear, elegir diseño y recorte, añadir y ordenar fotos, foco y textos por foto, visor, disuasión y peso estimado. */
final class GalleryController
{
    private const PICK = 24;

    /** @param \Closure(?array,?string,?string):array<string,mixed> $ctx */
    public function __construct(private readonly Services $sv, private readonly string $prefix, private readonly \Closure $ctx, private readonly string $ip)
    {
    }

    /** @param array<string,mixed> $user */
    public function handle(Request $r, array $user, string $p): ?Response
    {
        if (!str_starts_with($p, '/galleries')) {
            return null;
        }
        $post = $r->method === 'POST';
        if ($p === '/galleries') {
            return $this->need($user, 'media.read') ?? $this->index($r, $user);
        }
        if ($p === '/galleries/create' && $post) {
            return $this->need($user, 'media.write') ?? $this->create($r, $user);
        }
        if ($p === '/galleries/list.json' && !$post) {
            return $this->need($user, 'media.read') ?? $this->listJson($r);
        }
        if (!preg_match('#^/galleries/(gal-[0-9a-f]{12})(?:/([a-z]+))?$#', $p, $m)) {
            return null;
        }
        $g = $this->sv->galleries()->get($m[1]);
        if ($g === null) {
            return new Response(404, View::page('No encontrada', '<h1>Galería no encontrada</h1><p><a href="' . $this->url('/galleries') . '">Volver a las galerías</a></p>', ($this->ctx)($user, null, null)));
        }
        $action = $m[2] ?? 'view';
        if ($post) {
            return $this->need($user, 'media.write') ?? match ($action) {
                'settings' => $this->saveSettings($r, $user, $g),
                'items' => $this->saveItems($r, $user, $g),
                'add' => $this->add($r, $user, $g),
                'delete' => $this->delete($user, $g),
                default => null,
            };
        }
        return $action === 'view' ? ($this->need($user, 'media.read') ?? $this->detail($r, $user, $g)) : null;
    }

    private static function json(int $status, array $data): Response
    {
        return new Response($status, (string)json_encode($data, JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store, private']);
    }

    /** Selector de galerías del editor. */
    private function listJson(Request $r): Response
    {
        $out = [];
        foreach ($this->sv->galleries()->all((string)($r->query['q'] ?? '')) as $g) {
            $first = $this->sv->db->val('SELECT media_uid FROM gallery_items WHERE gallery_id = ? ORDER BY position LIMIT 1', [$g['id']]);
            $out[] = ['ref' => (string)$g['uid'], 'title' => (string)($g['title'] !== '' ? $g['title'] : $g['uid']), 'count' => (int)$g['n'], 'layout' => (string)$g['layout'],
                'thumb' => $first ? $this->url('/media/' . $first . '/thumb') : null];
        }
        return self::json(200, ['ok' => true, 'items' => $out, 'new_url' => $this->url('/galleries')]);
    }

    /** @param array<string,mixed> $user */
    private function index(Request $r, array $user, ?string $error = null, ?string $flash = null): Response
    {
        $csrf = ($this->ctx)($user, null, null)['csrf'];
        $q = mb_substr(trim((string)($r->query['q'] ?? '')), 0, 80);
        $body = '<h1>Galerías</h1><p class="muted">Una galería es un conjunto ordenado de fotos de la biblioteca con un diseño, un recorte y un visor. Se inserta en un contenido con el bloque «Galería» del editor.</p>';
        if (Authorizer::can($user, 'media.write')) {
            $o = '';
            foreach ($this->sv->designs()->byType('gallery') as $d) {
                $o .= '<option value="' . View::h($d['id']) . '">' . View::h($d['name']) . ' — ' . View::h($d['description']) . '</option>';
            }
            $body .= '<h2>Nueva galería</h2>' . Ui::form($this->url('/galleries/create'), $csrf,
                '<label>Título (solo para ti y para la accesibilidad)<input name="title" maxlength="120" required></label><label>Diseño<select name="layout">' . $o . '</select></label>' . Ui::button('Crear galería'));
        }
        $body .= '<h2>Tus galerías</h2><form method="get" action="' . $this->url('/galleries') . '" class="filters"><input type="search" name="q" value="' . View::h($q) . '" placeholder="Buscar por título" maxlength="80"><button>Buscar</button></form>';
        $all = $this->sv->galleries()->all($q);
        if ($all === []) {
            $body .= '<p>' . ($q === '' ? 'Aún no hay galerías.' : 'Sin resultados.') . '</p>';
        } else {
            $body .= '<ul class="menu">';
            foreach ($all as $g) {
                $body .= '<li><a href="' . $this->url('/galleries/' . $g['uid']) . '">' . View::h($g['title'] !== '' ? (string)$g['title'] : (string)$g['uid']) . '</a> <small>' . (int)$g['n'] . ' foto(s) · ' . View::h((string)$g['layout']) . '</small></li>';
            }
            $body .= '</ul>';
        }
        return new Response($error !== null ? 422 : 200, View::page('Galerías', $body, ($this->ctx)($user, $error, $flash)));
    }

    /** @param array<string,mixed> $user */
    private function create(Request $r, array $user): Response
    {
        try {
            $g = $this->sv->galleries()->create((string)($r->post['title'] ?? ''), (string)($r->post['layout'] ?? ''), (int)$user['id']);
        } catch (LuxException $e) {
            return $this->index($r, $user, $e->getMessage());
        }
        $this->audit('gallery.create', $user, (string)$g['uid']);
        return Response::redirect($this->url('/galleries/' . $g['uid']));
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $g */
    private function detail(Request $r, array $user, array $g, ?string $error = null, ?string $flash = null): Response
    {
        $csrf = ($this->ctx)($user, null, null)['csrf'];
        $w = Authorizer::can($user, 'media.write');
        $uid = (string)$g['uid'];
        $d = $this->sv->designs()->get((string)$g['layout']);
        $body = '<p><a href="' . $this->url('/galleries') . '">← Galerías</a></p><h1>' . View::h($g['title'] !== '' ? (string)$g['title'] : $uid) . '</h1>'
            . '<p class="muted">Identificador: <code>' . View::h($uid) . '</code>. Para usarla, añade el bloque «Galería» en el editor y elige esta galería'
            . ' (en el modo de texto, una línea con <code>[galeria:' . View::h($uid) . ']</code>).</p>';
        $refs = $this->sv->galleries()->references($uid);
        $body .= '<p>' . ($refs === [] ? 'Ningún contenido usa esta galería.' : 'Usada en: ' . implode(', ', array_map(fn(array $x): string => View::h($x['title']), $refs)) . '.') . '</p>';
        $items = $this->sv->galleries()->items($uid);
        // Ajustes
        if ($d === null || $d['type'] !== 'gallery') {
            $body .= '<p class="error">El diseño «' . View::h((string)$g['layout']) . '» ya no está disponible: elige otro y guarda.</p>';
        }
        $layouts = '';
        foreach ($this->sv->designs()->byType('gallery') as $x) {
            $layouts .= '<option value="' . View::h($x['id']) . '"' . ($x['id'] === $g['layout'] ? ' selected' : '') . '>' . View::h($x['name']) . '</option>';
        }
        $crops = '';
        foreach ((array)($d['gallery']['crops'] ?? ['free']) as $c) {
            $crops .= '<option value="' . View::h($c) . '"' . ($c === $g['crop'] ? ' selected' : '') . '>' . View::h(GalleryStore::CROP_LABEL[$c]) . '</option>';
        }
        $variants = '';
        foreach (GalleryStore::LB_VARIANTS as $k => $label) {
            $variants .= '<option value="' . $k . '"' . ($k === $g['lightbox']['variant'] ? ' selected' : '') . '>' . View::h($label) . '</option>';
        }
        $chk = static fn(string $n, string $label, bool $v): string => '<label class="check"><input type="checkbox" name="' . $n . '" value="1"' . ($v ? ' checked' : '') . '> ' . View::h($label) . '</label>';
        $lb = (array)$g['lightbox'];
        $fields = '';
        foreach ((array)($d['fields'] ?? []) as $name => $def) {
            $fields .= Ui::valueInput('options[' . $name . ']', $def, $g['options'][$name] ?? $d['defaults'][$name]);
        }
        $body .= '<h2>Ajustes</h2>' . Ui::form($this->url('/galleries/' . $uid . '/settings'), $csrf,
            '<label>Título<input name="title" value="' . View::h((string)$g['title']) . '" maxlength="120"></label>'
            . '<label>Diseño<select name="layout">' . $layouts . '</select></label>'
            . '<p class="muted">Si cambias de diseño, guarda para ver sus opciones; las opciones del diseño anterior se sustituyen por las del nuevo.</p>'
            . '<label>Recorte de las miniaturas<select name="crop">' . $crops . '</select></label>' . $fields
            . '<fieldset><legend>Visor al pulsar una foto</legend>' . $chk('lightbox[enabled]', 'Abrir las fotos a pantalla completa', (bool)$lb['enabled'])
            . '<label>Transición<select name="lightbox[variant]">' . $variants . '</select></label>'
            . $chk('lightbox[counter]', 'Mostrar contador (3 de 12)', (bool)$lb['counter']) . $chk('lightbox[captions]', 'Mostrar pies de foto', (bool)$lb['captions'])
            . $chk('lightbox[arrows]', 'Mostrar flechas', (bool)$lb['arrows']) . $chk('lightbox[loop]', 'Volver a la primera tras la última', (bool)$lb['loop'])
            . $chk('lightbox[preload]', 'Precargar la foto siguiente', (bool)$lb['preload']) . '</fieldset>'
            . '<fieldset><legend>Disuasión (no es protección)</legend>' . $chk('protect[nodrag]', 'Impedir arrastrar las fotos', (bool)$g['protect']['nodrag']) . $chk('protect[noctx]', 'Desactivar el menú contextual sobre las fotos', (bool)$g['protect']['noctx'])
            . '<p class="muted">Solo molesta al usuario ocasional: cualquiera puede descargar lo que ve en pantalla. Si necesitas protegerlas de verdad, no las publiques. Los originales nunca se publican.</p></fieldset>'
            . ($w ? Ui::button('Guardar ajustes') : ''));
        // Fotos
        $body .= '<h2>Fotos (' . count($items) . ')</h2>';
        if ($items === []) {
            $body .= '<p>La galería está vacía. Añade fotos de la biblioteca más abajo.</p>';
        } else {
            $rows = '';
            foreach ($items as $i => $it) {
                $n = 'items[' . $i . ']';
                $fx = $it['focus_x'] ?? $it['m_fx'];
                $fy = $it['focus_y'] ?? $it['m_fy'];
                $cs = '<option value="">Como la galería</option>';
                foreach (GalleryStore::CROP_LABEL as $k => $label) {
                    $cs .= '<option value="' . $k . '"' . ($it['crop'] === $k ? ' selected' : '') . '>' . View::h($label) . '</option>';
                }
                $missing = $it['m_sha'] === null;
                $rows .= '<li class="g-row" data-row>' . Ui::hidden($n . '[media]', (string)$it['media_uid'])
                    . ($missing ? '<p class="error">Esta foto ya no existe en la biblioteca: quítala.</p>'
                        : '<div class="g-thumb" data-focus><img src="' . $this->url('/media/' . $it['media_uid'] . '/thumb') . '" alt="" width="180"><span class="g-mark" data-mark></span></div>')
                    . '<div class="g-fields"><label>Orden<input type="number" name="' . $n . '[order]" value="' . ($i + 1) . '" min="1" max="9999" data-order></label><strong>' . View::h((string)($it['title'] ?? $it['m_title'] ?? $it['media_uid'])) . '</strong>'
                    . '<label>Texto alternativo<input name="' . $n . '[alt]" value="' . View::h((string)($it['alt'] ?? '')) . '" maxlength="300" placeholder="' . View::h((string)($it['m_alt'] ?? '')) . '"></label>'
                    . '<label>Pie de foto<input name="' . $n . '[caption]" value="' . View::h((string)($it['caption'] ?? '')) . '" maxlength="500" placeholder="' . View::h((string)($it['m_caption'] ?? '')) . '"></label>'
                    . '<label>Foco X<input type="number" name="' . $n . '[focus_x]" min="0" max="100" value="' . ($it['focus_x'] === null ? '' : (int)$it['focus_x']) . '" placeholder="' . (int)$it['m_fx'] . '" data-fx></label>'
                    . '<label>Foco Y<input type="number" name="' . $n . '[focus_y]" min="0" max="100" value="' . ($it['focus_y'] === null ? '' : (int)$it['focus_y']) . '" placeholder="' . (int)$it['m_fy'] . '" data-fy></label>'
                    . '<label>Recorte<select name="' . $n . '[crop]">' . $cs . '</select></label>'
                    . '<label class="check"><input type="checkbox" name="' . $n . '[remove]" value="1"> Quitar de la galería</label>'
                    . '<span class="g-move" data-move></span></div></li>';
            }
            $body .= ($w ? Ui::form($this->url('/galleries/' . $uid . '/items'), $csrf, '<p class="muted">Pulsa sobre la miniatura para marcar el punto que no debe quedar recortado (foco). El orden se cambia con ↑ ↓ o escribiendo el número «Orden» de cada foto.</p>'
                . '<ol class="g-rows" data-rows>' . $rows . '</ol>' . Ui::button('Guardar fotos')) : '<ol class="g-rows">' . $rows . '</ol>');
        }
        // Peso
        if ($d !== null && $items !== []) {
            $wgt = $this->weight($uid);
            $body .= '<h2>Peso estimado</h2><p>Un visitante descarga unos <strong>' . self::size($wgt['shown']) . '</strong> al ver la galería (' . $wgt['items'] . ' miniaturas, las demás se cargan al desplazarse) y la versión publicada contiene '
                . $wgt['files'] . ' archivos WebP, unos <strong>' . self::size($wgt['total']) . '</strong>. Es una estimación: el tamaño real depende de cada foto.</p>';
        }
        // Añadir
        if ($w) {
            $q = mb_substr(trim((string)($r->query['q'] ?? '')), 0, 80);
            $page = max(1, (int)($r->query['page'] ?? 1));
            $res = $this->sv->media()->search($q, self::PICK, ($page - 1) * self::PICK);
            $have = array_column($items, 'media_uid');
            $body .= '<h2>Añadir fotos</h2><form method="get" action="' . $this->url('/galleries/' . $uid) . '" class="filters"><input type="search" name="q" value="' . View::h($q) . '" placeholder="Buscar en la biblioteca" maxlength="80"><button>Buscar</button></form>';
            if ($res['items'] === []) {
                $body .= '<p>No hay fotos en la biblioteca' . ($q !== '' ? ' para esa búsqueda' : '') . '. <a href="' . $this->url('/media') . '">Sube fotos</a> primero.</p>';
            } else {
                $grid = '<ul class="media-grid">';
                foreach ($res['items'] as $m) {
                    $in = in_array($m['uid'], $have, true);
                    $grid .= '<li><label class="check"><input type="checkbox" name="media[]" value="' . View::h((string)$m['uid']) . '"' . ($in ? ' disabled' : '') . '> <img src="' . $this->url('/media/' . $m['uid'] . '/thumb') . '" alt="' . View::h((string)$m['alt']) . '" width="120" loading="lazy">'
                        . '<small>' . View::h((string)$m['title']) . ($in ? ' · ya está' : '') . '</small></label></li>';
                }
                $body .= Ui::form($this->url('/galleries/' . $uid . '/add'), $csrf, $grid . '</ul>' . Ui::button('Añadir las marcadas'));
                $pages = (int)ceil($res['total'] / self::PICK);
                if ($pages > 1) {
                    $qs = fn(int $n): string => $this->url('/galleries/' . $uid) . '?page=' . $n . ($q !== '' ? '&q=' . rawurlencode($q) : '');
                    $body .= '<p class="toolbar">' . ($page > 1 ? '<a class="btn" href="' . View::h($qs($page - 1)) . '">Anterior</a>' : '') . '<span>Página ' . $page . ' de ' . $pages . '</span>'
                        . ($page < $pages ? '<a class="btn" href="' . View::h($qs($page + 1)) . '">Siguiente</a>' : '') . '</p>';
                }
            }
            $body .= '<h2>Borrar la galería</h2>' . ($refs === [] ? Ui::form($this->url('/galleries/' . $uid . '/delete'), $csrf, '<p>Las fotos de la biblioteca no se borran.</p>' . Ui::button('Borrar galería', 'danger'))
                : '<p class="error">No se puede borrar mientras la usen ' . count($refs) . ' contenido(s).</p>');
        }
        $body .= '<script src="' . View::h($this->url('/assets/galleries.js')) . '" defer></script>';
        return new Response($error !== null ? 422 : 200, View::page((string)($g['title'] !== '' ? $g['title'] : $uid), $body, ($this->ctx)($user, $error, $flash)));
    }

    /** @return array{items:int,shown:int,files:int,total:int} */
    private function weight(string $uid): array
    {
        $snap = $this->sv->galleries()->snapshot([$uid]);
        $s = $this->sv->mediaSettings();
        $img = new ImageRenderer($snap['media'], $s->quality(), $s->maxWidth(), 736, null, true);
        $gr = new GalleryRenderer($snap['galleries'], $snap['media'], $this->sv->designs(), $this->sv->config->root . '/components', $img, static fn(): string => '', true);
        $gr->render($uid);
        $e = $img->estimate();
        return ['items' => $gr->stats['items'], 'shown' => $gr->stats['shown_bytes'], 'files' => $e['files'], 'total' => $e['bytes']];
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $g */
    private function saveSettings(Request $r, array $user, array $g): Response
    {
        try {
            $this->sv->galleries()->update((string)$g['uid'], [
                'title' => (string)($r->post['title'] ?? ''), 'layout' => (string)($r->post['layout'] ?? $g['layout']), 'crop' => (string)($r->post['crop'] ?? $g['crop']),
                'options' => is_array($r->post['options'] ?? null) ? $r->post['options'] : [], 'lightbox' => is_array($r->post['lightbox'] ?? null) ? $r->post['lightbox'] : [],
                'protect' => is_array($r->post['protect'] ?? null) ? $r->post['protect'] : [],
            ]);
        } catch (LuxException $e) {
            return $this->detail($r, $user, $g, $e->getMessage());
        }
        $this->audit('gallery.update', $user, (string)$g['uid']);
        return $this->detail($r, $user, (array)$this->sv->galleries()->get((string)$g['uid']), null, 'Ajustes guardados. Publica para que se vean en el sitio.');
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $g */
    private function saveItems(Request $r, array $user, array $g): Response
    {
        $rows = is_array($r->post['items'] ?? null) ? $r->post['items'] : [];
        $keep = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            $row['_o'] = (int)($row['order'] ?? 0) * 10000 + (int)$i;
            $keep[] = $row;
        }
        usort($keep, static fn(array $a, array $b): int => $a['_o'] <=> $b['_o']);
        try {
            $this->sv->galleries()->setItems((string)$g['uid'], array_map(static fn(array $row): array => [
                'media' => (string)($row['media'] ?? ''), 'alt' => $row['alt'] ?? null, 'caption' => $row['caption'] ?? null,
                'focus_x' => $row['focus_x'] ?? null, 'focus_y' => $row['focus_y'] ?? null, 'crop' => $row['crop'] ?? null,
            ], $keep));
        } catch (LuxException $e) {
            return $this->detail($r, $user, $g, $e->getMessage());
        }
        $this->audit('gallery.items', $user, (string)$g['uid']);
        return $this->detail($r, $user, (array)$this->sv->galleries()->get((string)$g['uid']), null, 'Fotos guardadas.');
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $g */
    private function add(Request $r, array $user, array $g): Response
    {
        $m = array_values(array_filter((array)($r->post['media'] ?? []), static fn($x): bool => is_string($x) && preg_match('/^img-[0-9a-f]{12}$/', $x) === 1));
        if ($m === []) {
            return $this->detail($r, $user, $g, 'No has marcado ninguna foto.');
        }
        try {
            $n = $this->sv->galleries()->addMedia((string)$g['uid'], $m);
        } catch (LuxException $e) {
            return $this->detail($r, $user, $g, $e->getMessage());
        }
        $this->audit('gallery.add', $user, (string)$g['uid'], ['n' => $n]);
        return $this->detail($r, $user, (array)$this->sv->galleries()->get((string)$g['uid']), null, $n . ' foto(s) añadida(s).');
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $g */
    private function delete(array $user, array $g): Response
    {
        try {
            $this->sv->galleries()->delete((string)$g['uid']);
        } catch (LuxException $e) {
            return $this->detail(new Request('GET', '/galleries/' . $g['uid']), $user, $g, $e->getMessage());
        }
        $this->audit('gallery.delete', $user, (string)$g['uid']);
        return $this->index(new Request('GET', '/galleries'), $user, null, 'Galería borrada.');
    }

    private static function size(int $b): string
    {
        return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
    }

    /** @param array<string,mixed> $user */
    private function need(array $user, string $perm): ?Response
    {
        return Authorizer::can($user, $perm) ? null : new Response(403, View::page('Prohibido', '<h1>Sin permiso</h1><p>Tu rol no permite esta acción.</p>', ($this->ctx)($user, null, null)));
    }

    private function url(string $p): string
    {
        return $this->prefix . $p;
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $meta */
    private function audit(string $event, array $user, ?string $target = null, array $meta = []): void
    {
        $this->sv->audit->log($event, (int)$user['id'], (string)$user['email'], $target, $meta, $this->ip);
    }
}
