<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

use LuxAeterna\Core\LuxException;
use LuxAeterna\Media\MediaSettings;
use LuxAeterna\Media\MediaStore;
use LuxAeterna\Security\Authorizer;

/** Biblioteca multimedia del panel: subida validada, búsqueda, metadatos editoriales, reemplazo y borrado con aviso de referencias. */
final class MediaController
{
    private const PER_PAGE = 24;
    private const MAX_FILES = 20;

    /** @param \Closure(?array,?string,?string):array<string,mixed> $ctx */
    public function __construct(private readonly Services $sv, private readonly string $prefix, private readonly \Closure $ctx, private readonly string $ip)
    {
    }

    /** @param array<string,mixed> $user */
    public function handle(Request $r, array $user, string $p): ?Response
    {
        if (!str_starts_with($p, '/media')) {
            return null;
        }
        $post = $r->method === 'POST';
        if ($p === '/media') {
            return $this->need($user, 'media.read') ?? $this->library($r, $user);
        }
        if ($p === '/media/upload' && $post) {
            return $this->need($user, 'media.write') ?? $this->upload($r, $user);
        }
        if ($p === '/media/settings' && $post) {
            return $this->need($user, 'site.manage') ?? $this->saveSettings($r, $user);
        }
        if ($p === '/media/gc' && $post) {
            return $this->need($user, 'site.manage') ?? $this->gc($r, $user);
        }
        if (!preg_match('#^/media/(img-[0-9a-f]{12})(?:/([a-z]+))?$#', $p, $m)) {
            return null;
        }
        $rec = $this->sv->media()->get($m[1]);
        if ($rec === null) {
            return new Response(404, View::page('No encontrada', '<h1>Imagen no encontrada</h1><p><a href="' . $this->url('/media') . '">Volver a la biblioteca</a></p>', ($this->ctx)($user, null, null)));
        }
        $action = $m[2] ?? 'view';
        if ($post) {
            return $this->need($user, 'media.write') ?? match ($action) {
                'save' => $this->save($r, $user, $rec),
                'replace' => $this->replace($r, $user, $rec),
                'delete' => $this->delete($user, $rec),
                default => null,
            };
        }
        return $this->need($user, $action === 'original' ? 'media.write' : 'media.read') ?? match ($action) {
            'view' => $this->detail($user, $rec),
            'thumb' => $this->thumb($rec),
            'original' => $this->original($rec),
            default => null,
        };
    }

    /** @param array<string,mixed> $user */
    private function library(Request $r, array $user, ?string $error = null, ?string $flash = null): Response
    {
        $q = mb_substr(trim((string)($r->query['q'] ?? '')), 0, 80);
        $page = max(1, (int)($r->query['page'] ?? 1));
        $res = $this->sv->media()->search($q, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $csrf = ($this->ctx)($user, null, null)['csrf'];
        $s = $this->sv->mediaSettings();
        $st = $this->sv->media()->stats();
        $body = '<h1>Biblioteca multimedia</h1>'
            . '<p class="muted">' . $st['count'] . ' imágenes · originales ' . self::size($st['original_bytes']) . ' (privados) · derivados WebP ' . self::size($st['derived_bytes']) . '. Formatos: JPEG, PNG, WebP y GIF sin animación; hasta '
            . round($s->maxUploadBytes() / 1048576) . ' MB y ' . round($s->maxPixels() / 1000000) . ' megapíxeles.</p>';
        if (Authorizer::can($user, 'media.write')) {
            $body .= '<h2>Subir imágenes</h2>' . Ui::form($this->url('/media/upload'), $csrf,
                '<label>Archivos<input type="file" name="files[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required></label>' . Ui::button('Subir'),
                ['enctype' => 'multipart/form-data']);
        }
        $body .= '<h2>Imágenes</h2><form method="get" action="' . $this->url('/media') . '" class="filters"><input type="search" name="q" value="' . View::h($q) . '" placeholder="Buscar por nombre, texto alternativo o crédito" maxlength="80"><button>Buscar</button></form>';
        if ($res['items'] === []) {
            $body .= '<p>' . ($q === '' ? 'Aún no hay imágenes.' : 'Sin resultados para «' . View::h($q) . '».') . '</p>';
        } else {
            $body .= '<ul class="media-grid">';
            foreach ($res['items'] as $m) {
                $body .= '<li><a href="' . $this->url('/media/' . $m['uid']) . '"><img src="' . $this->url('/media/' . $m['uid'] . '/thumb') . '" alt="' . View::h((string)$m['alt']) . '" width="180" loading="lazy"><strong>' . View::h((string)$m['title']) . '</strong></a>'
                    . '<small>' . (int)$m['width'] . '×' . (int)$m['height'] . ' · ' . self::size((int)$m['bytes']) . ((string)$m['alt'] === '' ? ' · sin texto alternativo' : '') . '</small></li>';
            }
            $body .= '</ul>';
            $pages = (int)ceil($res['total'] / self::PER_PAGE);
            if ($pages > 1) {
                $qs = fn(int $n): string => $this->url('/media') . '?page=' . $n . ($q !== '' ? '&q=' . rawurlencode($q) : '');
                $body .= '<p class="toolbar">' . ($page > 1 ? '<a class="btn" href="' . View::h($qs($page - 1)) . '">Anterior</a>' : '') . '<span>Página ' . $page . ' de ' . $pages . '</span>'
                    . ($page < $pages ? '<a class="btn" href="' . View::h($qs($page + 1)) . '">Siguiente</a>' : '') . '</p>';
            }
        }
        if (Authorizer::can($user, 'site.manage')) {
            $body .= '<h2>Calidad y tamaño de las imágenes publicadas</h2>' . Ui::form($this->url('/media/settings'), $csrf,
                '<label>Calidad WebP (' . MediaSettings::QUALITY_MIN . '–' . MediaSettings::QUALITY_MAX . ')<input type="number" name="quality" min="' . MediaSettings::QUALITY_MIN . '" max="' . MediaSettings::QUALITY_MAX . '" value="' . $s->quality() . '" required></label>'
                . '<label>Ancho máximo publicado, en píxeles<input type="number" name="max_width" min="' . MediaSettings::MAX_WIDTH_MIN . '" max="' . MediaSettings::MAX_WIDTH_MAX . '" step="10" value="' . $s->maxWidth() . '" required></label>'
                . Ui::button('Guardar'))
                . Ui::form($this->url('/media/gc'), $csrf, Ui::button('Limpiar derivados sin uso')) . '<p class="muted">La calidad es el parámetro de libwebp (compresión con pérdida): 80–85 es un buen punto de partida para fotografías; menos pesa menos y degrada más. '
                . 'Al cambiarla, la próxima publicación regenera los derivados desde los originales, que no se modifican. Solo se generan los tamaños que realmente usa cada página y nunca se amplía una foto pequeña.</p>';
        }
        return new Response($error !== null ? 422 : 200, View::page('Biblioteca multimedia', $body, ($this->ctx)($user, $error, $flash)));
    }

    /** @param array<string,mixed> $user */
    private function upload(Request $r, array $user): Response
    {
        $files = array_slice($r->files['files'] ?? [], 0, self::MAX_FILES);
        if ($files === []) {
            return $this->library($r, $user, 'No se ha recibido ningún archivo. Si el archivo es muy grande, comprueba el límite del servidor.');
        }
        $ok = 0;
        $dup = 0;
        $bad = [];
        $last = null;
        foreach ($files as $f) {
            try {
                if ($f['error'] !== UPLOAD_ERR_OK) {
                    throw new LuxException($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'El archivo supera el límite de subida del servidor.' : 'La subida no se completó.');
                }
                $m = $this->sv->media()->add($f['tmp_name'], $f['name'], (int)$user['id']);
                $last = $m;
                if ($m['duplicate']) {
                    $dup++;
                } else {
                    $ok++;
                    $this->audit('media.upload', $user, (string)$m['uid']);
                }
            } catch (LuxException $e) {
                $bad[] = self::clean($f['name']) . ': ' . $e->getMessage();
            }
        }
        $msg = $ok . ' subida(s)' . ($dup > 0 ? ", $dup ya estaba(n) en la biblioteca" : '') . ($bad !== [] ? ', ' . count($bad) . ' rechazada(s)' : '') . '.';
        if ($bad !== [] && $ok === 0 && $dup === 0) {
            return $this->library($r, $user, 'No se ha subido nada. ' . implode(' · ', array_slice($bad, 0, 5)));
        }
        if ($bad === [] && $last !== null && count($files) === 1) {
            return Response::redirect($this->url('/media/' . $last['uid']));
        }
        return $this->library($r, $user, null, $msg . ($bad !== [] ? ' ' . implode(' · ', array_slice($bad, 0, 5)) : ''));
    }

    /** @param array<string,mixed> $user */
    private function gc(Request $r, array $user): Response
    {
        $res = $this->sv->media()->gc($this->sv->mediaKeepKeys(), 7);
        $this->audit('media.gc', $user, null, $res);
        return $this->library($r, $user, null, 'Limpieza hecha: ' . $res['removed'] . ' derivados sin uso borrados (' . self::size($res['bytes']) . '). Los de las versiones retenidas y los usados en los últimos 7 días se conservan.');
    }

    /** @param array<string,mixed> $user */
    private function saveSettings(Request $r, array $user): Response
    {
        try {
            $v = MediaSettings::validate($r->post);
        } catch (LuxException $e) {
            return $this->library($r, $user, $e->getMessage());
        }
        $this->sv->settings->set('media', (string)json_encode($v));
        $this->audit('media.settings', $user, null, $v);
        return $this->library($r, $user, null, 'Ajustes guardados. Los derivados se regeneran desde los originales en la próxima publicación.');
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $m */
    private function detail(array $user, array $m, ?string $error = null, ?string $flash = null): Response
    {
        $csrf = ($this->ctx)($user, null, null)['csrf'];
        $refs = $this->sv->media()->references((string)$m['uid']);
        $w = Authorizer::can($user, 'media.write');
        $body = '<p><a href="' . $this->url('/media') . '">← Biblioteca</a></p><h1>' . View::h((string)$m['title']) . '</h1>'
            . '<p><img src="' . $this->url('/media/' . $m['uid'] . '/thumb') . '" alt="' . View::h((string)$m['alt']) . '" width="360"></p>'
            . '<p class="muted">' . (int)$m['width'] . '×' . (int)$m['height'] . ' px · ' . View::h((string)$m['mime']) . ' · ' . self::size((int)$m['bytes']) . ' · huella ' . View::h(substr((string)$m['sha256'], 0, 12)) . ' · subida ' . Ui::date((int)$m['created_at']) . '</p>'
            . ((string)$m['notes'] !== '' ? '<p class="flash">' . View::h((string)$m['notes']) . '</p>' : '')
            . '<h2>Usar en un contenido</h2><p>Pega esta línea en el cuerpo, en su propia línea: <code>[imagen:' . View::h((string)$m['uid']) . '|texto alternativo|pie opcional]</code>. '
            . 'Si dejas vacíos el texto alternativo o el pie, se usan los de esta ficha.</p>';
        if ($w) {
            $body .= '<h2>Datos editoriales</h2>' . Ui::form($this->url('/media/' . $m['uid'] . '/save'), $csrf,
                '<label>Nombre editorial<input name="title" value="' . View::h((string)$m['title']) . '" maxlength="120" required></label>'
                . '<label>Texto alternativo por defecto<input name="alt" value="' . View::h((string)$m['alt']) . '" maxlength="300"></label>'
                . '<label>Pie por defecto<input name="caption" value="' . View::h((string)$m['caption']) . '" maxlength="500"></label>'
                . '<label>Crédito<input name="credit" value="' . View::h((string)$m['credit']) . '" maxlength="200"></label>'
                . '<label>Foco horizontal (0 izquierda – 100 derecha), para recortes<input type="number" name="focus_x" min="0" max="100" value="' . (int)$m['focus_x'] . '"></label>'
                . '<label>Foco vertical (0 arriba – 100 abajo)<input type="number" name="focus_y" min="0" max="100" value="' . (int)$m['focus_y'] . '"></label>'
                . Ui::button('Guardar'));
        }
        $body .= '<h2>Dónde se usa</h2>';
        if ($refs === []) {
            $body .= '<p>Ningún contenido usa esta imagen.</p>';
        } else {
            $body .= '<ul>';
            foreach ($refs as $x) {
                $body .= '<li>' . View::h($x['title']) . ' (' . View::h($x['type']) . ') · ' . ($x['published'] ? 'publicada' : '') . ($x['published'] && $x['editing'] ? ' y ' : '') . ($x['editing'] ? 'en edición' : '') . '</li>';
            }
            $body .= '</ul>';
        }
        if ($w) {
            $body .= '<h2>Reemplazar el archivo</h2><p class="muted">Conserva el identificador, los textos y todos los usos. Los derivados se regeneran en la próxima publicación.</p>'
                . Ui::form($this->url('/media/' . $m['uid'] . '/replace'), $csrf, '<label>Nuevo archivo<input type="file" name="file" accept="image/jpeg,image/png,image/webp,image/gif" required></label>' . Ui::button('Reemplazar'), ['enctype' => 'multipart/form-data'])
                . '<p><a class="btn" href="' . $this->url('/media/' . $m['uid'] . '/original') . '">Descargar el original</a></p><h2>Borrar</h2>'
                . ($refs === [] ? Ui::form($this->url('/media/' . $m['uid'] . '/delete'), $csrf, '<p>Se borrarán el original y todos sus derivados. No se puede deshacer.</p>' . Ui::button('Borrar imagen', 'danger'))
                    : '<p class="error">No se puede borrar mientras la usen ' . count($refs) . ' contenido(s). Quítala de ellos o reemplaza el archivo.</p>');
        }
        return new Response($error !== null ? 422 : 200, View::page((string)$m['title'], $body, ($this->ctx)($user, $error, $flash)));
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $m */
    private function save(Request $r, array $user, array $m): Response
    {
        try {
            $this->sv->media()->update((string)$m['uid'], array_intersect_key($r->post, array_flip(['title', 'alt', 'caption', 'credit', 'focus_x', 'focus_y'])));
        } catch (LuxException $e) {
            return $this->detail($user, $m, $e->getMessage());
        }
        $this->audit('media.update', $user, (string)$m['uid']);
        return $this->detail($user, (array)$this->sv->media()->get((string)$m['uid']), null, 'Cambios guardados.');
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $m */
    private function replace(Request $r, array $user, array $m): Response
    {
        $f = ($r->files['file'] ?? [])[0] ?? null;
        try {
            if ($f === null || $f['error'] !== UPLOAD_ERR_OK) {
                throw new LuxException('No se ha recibido el archivo nuevo.');
            }
            $this->sv->media()->replace((string)$m['uid'], $f['tmp_name']);
        } catch (LuxException $e) {
            return $this->detail($user, $m, $e->getMessage());
        }
        $this->audit('media.replace', $user, (string)$m['uid']);
        return $this->detail($user, (array)$this->sv->media()->get((string)$m['uid']), null, 'Archivo reemplazado. Publica para que se vea en el sitio.');
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $m */
    private function delete(array $user, array $m): Response
    {
        try {
            $this->sv->media()->delete((string)$m['uid']);
        } catch (LuxException $e) {
            return $this->detail($user, $m, $e->getMessage());
        }
        $this->audit('media.delete', $user, (string)$m['uid']);
        return $this->library(new Request('GET', '/media'), $user, null, 'Imagen borrada.');
    }

    /** @param array<string,mixed> $m */
    private function thumb(array $m): Response
    {
        try {
            $d = array_values($this->sv->media()->ensure($m, [$this->sv->media()->thumbRecipe()]))[0];
        } catch (LuxException) {
            return new Response(404, 'No disponible', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response(200, (string)file_get_contents($d['file']), ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** El original puede llevar metadatos privados (GPS): solo para quien gestiona medios, como descarga y sin cachés. @param array<string,mixed> $m */
    private function original(array $m): Response
    {
        $file = $this->sv->media()->originalPath($m);
        if (!is_file($file)) {
            return new Response(404, 'No disponible', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return new Response(200, (string)file_get_contents($file), [
            'Content-Type' => (string)$m['mime'], 'Content-Disposition' => 'attachment; filename="' . $m['uid'] . '.' . $m['ext'] . '"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox',
        ]);
    }

    private static function size(int $b): string
    {
        return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
    }

    private static function clean(string $name): string
    {
        return mb_substr((string)preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))), 0, 60);
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
