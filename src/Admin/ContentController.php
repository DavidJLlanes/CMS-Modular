<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

use LuxAeterna\Content\Blocks;
use LuxAeterna\Content\ConflictException;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Security\Authorizer;

/** Rutas editoriales: listado, edición, autoguardado, revisiones, papelera, publicación y previsualización. */
final class ContentController
{
    private const STATUS = ['draft' => 'Borrador', 'published' => 'Publicado', 'trashed' => 'Papelera'];

    /** @param \Closure(?array,?string,?string):array<string,mixed> $ctx */
    public function __construct(private readonly Services $sv, private readonly string $prefix, private readonly \Closure $ctx, private readonly string $ip)
    {
    }

    /** @param array<string,mixed> $user */
    public function handle(Request $r, array $user, string $p, string $sid): ?Response
    {
        $post = $r->method === 'POST';
        if ($p === '/preview/style.css') {
            $uid = preg_match('/^c_[A-Za-z0-9]{4,40}$/', $r->query['u'] ?? '') ? (string)$r->query['u'] : 'preview';
            $type = preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $r->query['t'] ?? '') ? (string)$r->query['t'] : 'page';
            $colors = [];
            foreach (array_slice(explode(',', (string)($r->query['c'] ?? '')), 0, 100) as $hex) {
                if (preg_match('/^[0-9a-f]{6}$/', $hex)) {
                    $colors[] = '#' . $hex;
                }
            }
            return $this->need($user, 'content.read') ?? new Response(200, $this->sv->preview->css($uid, $type, $colors), ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'no-store, private']);
        }
        if (preg_match('#^/preview/(font)/([a-z0-9-]{1,40})/([a-z0-9-]+\.woff2)$#', $p, $m) || preg_match('#^/preview/(logo)/([A-Za-z0-9][A-Za-z0-9_.-]{0,60})$#', $p, $m)) {
            $a = $this->need($user, 'content.read') ?? $this->sv->preview->asset($m[1], $m[2], $m[3] ?? '');
            if ($a instanceof Response) {
                return $a;
            }
            return $a === null ? new Response(404, 'No encontrado', ['Content-Type' => 'text/plain; charset=utf-8'])
                : new Response(200, $a[1], ['Content-Type' => $a[0], 'Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'private, max-age=60', 'X-Content-Type-Options' => 'nosniff']);
        }
        if (preg_match('#^/preview/media/([a-z0-9._-]{10,80})$#', $p, $m)) {
            $a = $this->need($user, 'content.read') ?? $this->sv->preview->image($m[1]);
            if ($a instanceof Response) {
                return $a;
            }
            return $a === null ? new Response(404, 'No encontrado', ['Content-Type' => 'text/plain; charset=utf-8'])
                : new Response(200, $a[1], ['Content-Type' => $a[0], 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
        }
        if ($p === '/content') {
            return $this->need($user, 'content.read') ?? $this->listing($r, $user, $sid);
        }
        if ($p === '/content/new') {
            return $this->need($user, 'content.write') ?? ($post ? $this->create($r, $user, $sid) : $this->newForm($r, $user));
        }
        if (!preg_match('#^/content/(\d+)(?:/([a-z]+))?(?:/(\d+))?(?:/([a-z]+))?$#', $p, $m)) {
            return null;
        }
        $id = (int)$m[1];
        $action = $m[2] ?? 'edit';
        $c = $this->sv->content->get($id);
        if ($c === null) {
            return new Response(404, View::page('No encontrado', '<h1>Contenido no encontrado</h1>', ($this->ctx)($user, null, null)));
        }
        $perm = match ($action) {
            'edit', 'revisions', 'preview' => 'content.read',
            'publish', 'unpublish' => 'content.publish',
            'delete' => 'users.manage',
            default => 'content.write',
        };
        if (($deny = $this->need($user, $perm)) !== null) {
            return $deny;
        }
        if (!$post && !in_array($action, ['edit', 'revisions', 'preview'], true)) {
            return Response::redirect($this->url("/content/$id/edit"));
        }
        return match ($action) {
            'edit' => $this->editForm($c, $user, $sid, $r),
            'save' => $this->save($c, $r, $user, $sid),
            'autosave' => $this->autosave($c, $r, $user),
            'publish', 'unpublish', 'trash', 'restore', 'delete' => $this->transition($action, $c, $r, $user),
            'revisions' => ($m[3] ?? '') !== '' ? ($post && ($m[4] ?? '') === 'restore' ? $this->restoreRevision($c, (int)$m[3], $r, $user) : null) : $this->revisionsPage($c, $user, $sid),
            'preview' => $this->preview($c, $r, $user),
            default => null,
        };
    }

    /* ---------- listado ---------- */

    private function listing(Request $r, array $user, string $sid): Response
    {
        $type = $r->query['type'] ?? '';
        $status = $r->query['status'] ?? 'all';
        $q = mb_substr($r->query['q'] ?? '', 0, 80);
        $rows = $this->sv->content->search($this->sv->types->has($type) ? $type : null, $status, $q, 100);
        $trs = '';
        foreach ($rows as $c) {
            $dirty = $c['status'] === 'published' && (int)$c['published_rev'] !== (int)$c['current_rev'];
            $trs .= '<tr><td><a href="' . $this->url('/content/' . $c['id'] . '/edit') . '">' . View::h((string)$c['title'] ?: '(sin título)') . '</a></td><td>' . View::h($this->sv->types->label((string)$c['type']))
                . '</td><td>' . self::STATUS[$c['status']] . ($dirty ? ' <small>(cambios sin publicar)</small>' : '') . '</td><td>' . Ui::date((int)$c['updated_at']) . '</td></tr>';
        }
        $newLinks = '';
        foreach ($this->sv->types->labels() as $k => $label) {
            $newLinks .= '<a class="btn" href="' . $this->url('/content/new?type=' . $k) . '">Nuevo: ' . View::h($label) . '</a> ';
        }
        $opts = '<option value="">Todos los tipos</option>';
        foreach ($this->sv->types->labels() as $k => $label) {
            $opts .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . View::h($label) . '</option>';
        }
        $st = '';
        foreach (['all' => 'Todos (sin papelera)'] + self::STATUS as $k => $label) {
            $st .= '<option value="' . $k . '"' . ($status === $k ? ' selected' : '') . '>' . $label . '</option>';
        }
        $body = '<h1>Contenido</h1><p>' . $newLinks . '</p><form method="get" action="' . $this->url('/content') . '" class="filters"><input name="q" value="' . View::h($q) . '" placeholder="Buscar por título" maxlength="80"><select name="type">' . $opts . '</select><select name="status">' . $st . '</select><button>Filtrar</button></form>'
            . '<div class="scroll"><table><thead><tr><th>Título</th><th>Tipo</th><th>Estado</th><th>Modificado</th></tr></thead><tbody>' . ($trs ?: '<tr><td colspan="4">Nada que mostrar.</td></tr>') . '</tbody></table></div>';
        return new Response(200, View::page('Contenido', $body, ($this->ctx)($user, null, null)));
    }

    /* ---------- crear y editar ---------- */

    private function newForm(Request $r, array $user, ?string $error = null, array $values = []): Response
    {
        $type = $this->sv->types->has($r->query['type'] ?? $r->input('type')) ? ($r->query['type'] ?? $r->input('type')) : 'page';
        $body = '<h1>Nuevo: ' . View::h($this->sv->types->label($type)) . '</h1>'
            . Ui::form($this->url('/content/new'), $this->csrf($user), Ui::hidden('type', $type)
                . '<label>Título<input name="title" required maxlength="200" value="' . View::h((string)($values['title'] ?? '')) . '"></label><button>Crear borrador</button>');
        return new Response($error !== null ? 422 : 200, View::page('Nuevo contenido', $body, ($this->ctx)($user, $error, null)));
    }

    private function create(Request $r, array $user, string $sid): Response
    {
        try {
            $type = $r->input('type');
            $id = $this->sv->content->create($type, ['title' => $r->input('title'), 'blocks' => []], (int)$user['id']);
            $this->audit('content.create', $user, $id);
            return Response::redirect($this->url("/content/$id/edit"));
        } catch (LuxException $e) {
            return $this->newForm($r, $user, $e->getMessage(), ['title' => $r->input('title')]);
        }
    }

    /** @param array<string,mixed> $c */
    private function editForm(array $c, array $user, string $sid, Request $r, ?string $error = null, ?array $values = null, int $status = 200): Response
    {
        $id = (int)$c['id'];
        $csrf = $this->csrf($user);
        $auto = $values === null ? $this->sv->content->autosave($id) : null;
        $useAuto = $auto !== null && ($r->query['autosave'] ?? '') === '1';
        $rev = $values ?? ($useAuto ? $auto : $c['rev']);
        $trashed = $c['status'] === 'trashed';
        $banner = '';
        if ($auto !== null && !$useAuto) {
            $banner = '<p class="flash">Hay un autoguardado más reciente (' . Ui::date((int)$auto['created_at']) . '). <a href="' . $this->url("/content/$id/edit?autosave=1") . '">Recuperarlo</a></p>';
        }
        $fields = '';
        foreach ($this->sv->types->fields((string)$c['type']) as $name => $def) {
            $fields .= Ui::fieldInput($name, $def, $rev['fields'][$name] ?? '');
        }
        $catIds = $values['cats'] ?? $this->sv->taxonomy->idsFor($id, 'category');
        $cats = '';
        foreach ($this->sv->taxonomy->all('category') as $t) {
            $cats .= '<label class="check"><input type="checkbox" name="cats[]" value="' . (int)$t['id'] . '"' . (in_array((int)$t['id'], $catIds, true) ? ' checked' : '') . '> ' . View::h((string)$t['name']) . '</label>';
        }
        $tagNames = $values['tags'] ?? implode(', ', array_column(array_filter($this->sv->taxonomy->all('tag'), fn($t) => in_array((int)$t['id'], $this->sv->taxonomy->idsFor($id, 'tag'), true)), 'name'));
        $stateLabel = $c['status'] === 'published' ? 'Publicado' . ($c['dirty'] ? ' (con cambios sin publicar)' : '') : self::STATUS[$c['status']];
        $slugPath = $this->sv->types->path((string)$c['type'], (string)$rev['slug']);
        $main = $trashed ? '<p class="error">Este contenido está en la papelera. Restáuralo para editarlo.</p>' : Ui::form($this->url("/content/$id/save"), $csrf,
            Ui::hidden('version', (int)$c['version'])
            . '<label>Título<input name="title" required maxlength="200" value="' . View::h((string)$rev['title']) . '"></label>'
            . '<label>Slug <small>(ruta pública: ' . View::h($slugPath) . ')</small><input name="slug" maxlength="80" value="' . View::h((string)$rev['slug']) . '"></label>'
            . '<label>Resumen <small>(descripción para buscadores y listados, máx. 300)</small><textarea name="summary" rows="2" maxlength="300">' . View::h((string)$rev['summary']) . '</textarea></label>'
            . $fields
            . $this->editorHtml($rev['blocks'], $c)
            . ($c['type'] !== 'page' && $cats !== '' ? '<fieldset><legend>Categorías</legend>' . $cats . '</fieldset>' : '')
            . ($c['type'] !== 'page' ? '<label>Etiquetas <small>(separadas por comas)</small><input name="tags" maxlength="400" value="' . View::h((string)$tagNames) . '"></label>' : '')
            . '<p><span id="autosave-status" class="muted" aria-live="polite"></span></p>' . Ui::button('Guardar'),
            ['data-autosave' => $this->url("/content/$id/autosave")]);
        $actions = '';
        $post = fn(string $a, string $label, string $cls = '') => Ui::form($this->url("/content/$id/$a"), $csrf, Ui::hidden('version', (int)$c['version']) . Ui::button($label, $cls), ['class' => 'inline']);
        if (!$trashed) {
            if (Authorizer::can($user, 'content.publish')) {
                $actions .= $post('publish', $c['status'] === 'published' && !$c['dirty'] ? 'Publicado ✓' : 'Publicar');
                if ($c['status'] === 'published') {
                    $actions .= $post('unpublish', 'Retirar', 'secondary');
                }
            }
            $actions .= $post('trash', 'A la papelera', 'danger');
        } else {
            $actions .= $post('restore', 'Restaurar');
            if (Authorizer::can($user, 'users.manage')) {
                $actions .= $post('delete', 'Borrar definitivamente', 'danger');
            }
        }
        $body = '<h1>' . View::h((string)$c['rev']['title'] ?: '(sin título)') . '</h1><p class="muted">' . View::h($this->sv->types->label((string)$c['type'])) . ' · ' . $stateLabel . '</p>' . $banner
            . '<p class="toolbar"><a class="btn" href="' . $this->url("/content/$id/preview") . '">Previsualizar</a> <a class="btn" href="' . $this->url("/content/$id/revisions") . '">Revisiones</a> ' . $actions . '</p>'
            . $main . ($trashed ? '' : self::editorScripts($this->url('/assets/editor/'), $this->url('/assets/autosave.js')));
        return new Response($error !== null ? $status : 200, View::page('Editar', $body, ($this->ctx)($user, $error, null)));
    }

    /** Módulos del editor en orden de carga (cada uno cuelga de `LuxEditor`); solo se envían en la pantalla de edición. */
    public const EDITOR_JS = ['model', 'html', 'history', 'lint', 'storage', 'blocks', 'inspector', 'media-picker', 'main'];

    private static function editorScripts(string $base, string $autosave): string
    {
        $s = '<link rel="stylesheet" href="' . View::h($base . 'editor.css') . '">';
        foreach (self::EDITOR_JS as $m) {
            $s .= '<script src="' . View::h($base . $m . '.js') . '" defer></script>';
        }
        return $s . '<script src="' . View::h($autosave) . '" defer></script>';
    }

    /**
     * Zona del editor. El campo JSON no tiene `name` hasta que el editor arranca: si el JavaScript falla, el texto simple
     * (solo cuando el contenido no usa formato rico) sigue funcionando y nunca se envían dos versiones distintas.
     * @param list<array<string,mixed>> $blocks @param array<string,mixed> $c
     */
    private function editorHtml(array $blocks, array $c): string
    {
        $json = (string)json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        $plain = Blocks::isPlain($blocks);
        $fallback = $plain
            ? '<label class="lux-fallback">Contenido <small>(bloques separados por línea en blanco: <code>## Título</code>, <code>- lista</code>, <code>&gt; cita</code>)</small><textarea name="body" rows="16" class="body">' . View::h(Blocks::toText($blocks)) . '</textarea></label>'
            : '<p class="lux-fallback error">Este contenido usa formato avanzado (columnas, tablas, estilos…) y necesita el editor completo, que requiere JavaScript. Tu contenido está a salvo: no se modificará si guardas sin él.</p>';
        return '<div class="lux-editor-root" data-lux-editor data-media-list="' . View::h($this->url('/media/list.json')) . '" data-media-upload="' . View::h($this->url('/media/upload.json'))
            . '" data-media-thumb="' . View::h($this->url('/media/')) . '" data-key="' . View::h('lux-editor-' . (string)$c['uid']) . '" data-can-upload="1">'
            . '<input type="hidden" data-body-json value="' . View::h($json) . '">' . $fallback . '</div>';
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function dataFrom(Request $r, array $c): array
    {
        $fields = [];
        foreach (array_keys($this->sv->types->fields((string)$c['type'])) as $name) {
            $fields[$name] = $r->post['f_' . $name] ?? ($this->sv->types->fields((string)$c['type'])[$name]['type'] === 'bool' ? false : '');
        }
        return ['title' => $r->input('title'), 'slug' => $r->input('slug'), 'summary' => $r->input('summary'), 'fields' => $fields, 'blocks' => $this->blocksFrom($r, $c)];
    }

    /**
     * Cuerpo enviado: JSON del editor (esquema 2, se valida siempre en servidor), o texto simple sin JavaScript, o
     * nada (se conserva el actual: p. ej. si el editor no cargó y el contenido solo se edita con el editor completo).
     * @param array<string,mixed> $c @return list<array<string,mixed>>
     */
    private function blocksFrom(Request $r, array $c): array
    {
        $json = (string)($r->post['body_json'] ?? '');
        if ($json !== '') {
            if (strlen($json) > 2_000_000) {
                throw new LuxException('El contenido es demasiado grande.');
            }
            $d = json_decode($json, true, 32);
            if (!is_array($d)) {
                throw new LuxException('El contenido del editor no es válido (JSON).');
            }
            return Blocks::validate($d);
        }
        return isset($r->post['body']) ? Blocks::fromText($r->input('body')) : (array)$c['rev']['blocks'];
    }

    private function save(array $c, Request $r, array $user, string $sid): Response
    {
        $id = (int)$c['id'];
        $values = ['title' => $r->input('title'), 'slug' => $r->input('slug'), 'summary' => $r->input('summary'), 'fields' => [], 'blocks' => [], 'cats' => array_map('intval', array_filter((array)($r->post['cats'] ?? []), 'is_scalar')), 'tags' => $r->input('tags')];
        foreach (array_keys($this->sv->types->fields((string)$c['type'])) as $name) {
            $values['fields'][$name] = $r->post['f_' . $name] ?? '';
        }
        try {
            $data = $this->dataFrom($r, $c);
            $ver = $this->sv->content->save($id, (int)$r->input('version'), $data, (int)$user['id']);
            if ($c['type'] !== 'page') {
                $tags = array_values(array_filter(array_map('trim', explode(',', $r->input('tags')))));
                $this->sv->taxonomy->assign($id, $values['cats'], $tags);
            }
            $this->audit('content.save', $user, $id, ['version' => $ver]);
            return Response::redirect($this->url("/content/$id/edit"));
        } catch (ConflictException $e) {
            $fresh = $this->sv->content->get($id) ?? $c;
            $values['blocks'] = $this->safeBlocks($r);
            $this->audit('content.conflict', $user, $id);
            return $this->editForm($fresh, $user, $sid, $r, $e->getMessage() . ' Se muestra tu texto; si guardas de nuevo, la versión de la otra persona queda en el historial de revisiones.', $values, 409);
        } catch (LuxException $e) {
            $values['blocks'] = $this->safeBlocks($r);
            return $this->editForm($c, $user, $sid, $r, $e->getMessage(), $values, 422);
        }
    }

    /** @return list<array<string,mixed>> */
    private function safeBlocks(Request $r): array
    {
        $json = (string)($r->post['body_json'] ?? '');
        if ($json !== '') {
            $d = json_decode($json, true, 32);
            // Para volver a mostrar lo escrito tras un error se pasa tal cual al editor, que lo sanea al cargar; el servidor revalida al guardar.
            return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
        }
        $text = $r->input('body');
        try {
            return Blocks::fromText($text);
        } catch (LuxException) {
            return [['type' => 'paragraph', 'text' => $text]];
        }
    }

    private function autosave(array $c, Request $r, array $user): Response
    {
        $json = static fn(int $status, array $data): Response => new Response($status, (string)json_encode($data, JSON_UNESCAPED_UNICODE), ['Content-Type' => 'application/json; charset=utf-8']);
        try {
            $this->sv->content->autosaveWrite((int)$c['id'], (int)$r->input('version'), $this->dataFrom($r, $c), (int)$user['id']);
            return $json(200, ['ok' => true, 'at' => date('H:i:s')]);
        } catch (ConflictException $e) {
            return $json(409, ['ok' => false, 'error' => 'Otra persona o pestaña ha modificado este contenido. Copia tu texto y recarga el editor.']);
        } catch (LuxException $e) {
            return $json(422, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /* ---------- estados ---------- */

    private function transition(string $action, array $c, Request $r, array $user): Response
    {
        $id = (int)$c['id'];
        $ver = (int)$r->input('version');
        try {
            $home = $this->sv->settings->get('home_uid');
            match ($action) {
                'publish' => $this->sv->content->publish($id, $ver),
                'unpublish' => $this->sv->content->unpublish($id, $ver, $home),
                'trash' => $this->sv->content->trash($id, $ver, $home),
                'restore' => $this->sv->content->restoreFromTrash($id, $ver),
                'delete' => $this->sv->content->deletePermanently($id),
            };
            $this->audit('content.' . $action, $user, $id);
            return Response::redirect($this->url($action === 'delete' ? '/content' : "/content/$id/edit"));
        } catch (LuxException $e) {
            $fresh = $this->sv->content->get($id) ?? $c;
            return $this->editForm($fresh, $user, '', $r, $e->getMessage(), null, $e instanceof ConflictException ? 409 : 422);
        }
    }

    /* ---------- revisiones ---------- */

    private function revisionsPage(array $c, array $user, string $sid): Response
    {
        $id = (int)$c['id'];
        $csrf = $this->csrf($user);
        $rows = '';
        foreach ($this->sv->content->revisions($id) as $rev) {
            $isCur = (int)$rev['id'] === (int)$c['current_rev'];
            $isPub = $c['published_rev'] !== null && (int)$rev['id'] === (int)$c['published_rev'];
            $label = ['save' => 'Guardado', 'restore' => 'Restauración'][$rev['kind']] ?? $rev['kind'];
            $rows .= '<tr><td>#' . (int)$rev['number'] . '</td><td>' . Ui::date((int)$rev['created_at']) . '</td><td>' . View::h($label) . ($isCur ? ' · <strong>en edición</strong>' : '') . ($isPub ? ' · <strong>publicada</strong>' : '')
                . '</td><td>' . View::h((string)$rev['title']) . '</td><td><a href="' . $this->url("/content/$id/preview/" . (int)$rev['number']) . '">Ver</a> '
                . (!$isCur && $c['status'] !== 'trashed' && Authorizer::can($user, 'content.write') ? Ui::form($this->url("/content/$id/revisions/" . (int)$rev['number'] . '/restore'), $csrf, Ui::hidden('version', (int)$c['version']) . Ui::button('Restaurar', 'link'), ['class' => 'inline']) : '') . '</td></tr>';
        }
        $body = '<h1>Revisiones</h1><p><a href="' . $this->url("/content/$id/edit") . '">← Volver al editor</a></p><p class="muted">Restaurar crea una revisión nueva con ese contenido; no borra nada del historial ni publica.</p>'
            . '<div class="scroll"><table><thead><tr><th>Nº</th><th>Fecha</th><th>Tipo</th><th>Título</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        return new Response(200, View::page('Revisiones', $body, ($this->ctx)($user, null, null)));
    }

    private function restoreRevision(array $c, int $number, Request $r, array $user): Response
    {
        $id = (int)$c['id'];
        try {
            $this->sv->content->restoreRevision($id, $number, (int)$r->input('version'), (int)$user['id']);
            $this->audit('content.restore_revision', $user, $id, ['number' => $number]);
            return Response::redirect($this->url("/content/$id/edit"));
        } catch (LuxException $e) {
            return $this->editForm($this->sv->content->get($id) ?? $c, $user, '', $r, $e->getMessage(), null, $e instanceof ConflictException ? 409 : 422);
        }
    }

    /* ---------- previsualización ---------- */

    private function preview(array $c, Request $r, array $user): Response
    {
        $id = (int)$c['id'];
        $parts = explode('/', trim(substr($r->path, strlen($this->prefix)), '/'));
        $number = isset($parts[3]) && ctype_digit($parts[3]) ? (int)$parts[3] : null;
        $frame = ($r->query['frame'] ?? '') === '1';
        if ($frame) {
            $rev = $number !== null ? $this->sv->content->revisionByNumber($id, $number) : (($r->query['src'] ?? '') === 'auto' ? $this->sv->content->autosave($id) : null) ?? $c['rev'];
            if ($rev === null) {
                return new Response(404, 'Revisión no encontrada', ['Content-Type' => 'text/plain; charset=utf-8']);
            }
            return new Response(200, $this->sv->preview->html((string)$c['type'], $rev, \LuxAeterna\Publishing\Preview::CSS_URL, (string)$c['uid']), [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
                'X-Frame-Options' => 'SAMEORIGIN',
                'X-Robots-Tag' => 'noindex, nofollow, noarchive',
                'Cache-Control' => 'no-store, private',
            ]);
        }
        $mode = ($r->query['mode'] ?? 'mobile') === 'desktop' ? 'desktop' : 'mobile';
        $base = $this->url("/content/$id/preview" . ($number !== null ? "/$number" : ''));
        $src = $base . '?frame=1' . (($r->query['src'] ?? '') === 'auto' ? '&src=auto' : '');
        $body = '<h1>Previsualización</h1><p><a href="' . $this->url("/content/$id/edit") . '">← Volver al editor</a> · <a href="' . $base . '?mode=mobile">Móvil</a> · <a href="' . $base . '?mode=desktop">Escritorio</a>'
            . ' · <a href="' . View::h($src) . '" target="_blank" rel="noopener">Abrir sola</a></p><p class="muted">Vista privada con las mismas plantillas que la publicación. No se indexa ni se guarda en el sitio.</p>'
            . '<iframe class="pv pv-' . $mode . '" src="' . View::h($src) . '" title="Previsualización ' . $mode . '" sandbox=""></iframe>';
        return new Response(200, View::page('Previsualización', $body, ($this->ctx)($user, null, null)));
    }

    /* ---------- utilidades ---------- */

    private function need(array $user, string $perm): ?Response
    {
        return Authorizer::can($user, $perm) ? null : new Response(403, View::page('Prohibido', '<h1>Sin permiso</h1><p>Tu rol no permite esta acción.</p>', ($this->ctx)($user, null, null)));
    }

    private function url(string $p): string
    {
        return $this->prefix . $p;
    }

    private function csrf(array $user): string
    {
        return ($this->ctx)($user, null, null)['csrf'];
    }

    /** @param array<string,scalar> $meta */
    private function audit(string $event, array $user, int $id, array $meta = []): void
    {
        $this->sv->audit->log($event, (int)$user['id'], (string)$user['email'], 'content:' . $id, $meta, $this->ip);
    }
}
