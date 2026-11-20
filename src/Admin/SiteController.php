<?php
declare(strict_types=1);

namespace CMSModular\Admin;

use CMSModular\Content\Menus;
use CMSModular\Content\Taxonomy;
use CMSModular\Core\LuxException;
use CMSModular\Security\Authorizer;

/** Categorías y etiquetas, menús, ajustes del sitio y generación manual del sitio. */
final class SiteController
{
    /** @param \Closure(?array,?string,?string):array<string,mixed> $ctx */
    public function __construct(private readonly Services $sv, private readonly string $prefix, private readonly \Closure $ctx, private readonly string $ip)
    {
    }

    /** @param array<string,mixed> $user */
    public function handle(Request $r, array $user, string $p): ?Response
    {
        $post = $r->method === 'POST';
        if (str_starts_with($p, '/terms')) {
            return $this->need($user, 'content.write') ?? $this->terms($r, $user, $p, $post);
        }
        if (str_starts_with($p, '/menus')) {
            return $this->need($user, 'site.manage') ?? $this->menus($r, $user, $p, $post);
        }
        if ($p === '/settings') {
            return $this->need($user, 'site.manage') ?? ($post ? $this->saveSettings($r, $user) : $this->settings($user));
        }
        if ($p === '/site/publish' && $post) {
            return $this->need($user, 'site.publish') ?? $this->runJob($user, false);
        }
        if (str_starts_with($p, '/publish')) {
            return $this->need($user, 'site.publish') ?? $this->publications($r, $user, $p, $post);
        }
        return null;
    }

    /* ---------- términos ---------- */

    private function terms(Request $r, array $user, string $p, bool $post, ?string $error = null): Response
    {
        try {
            if ($post && $p === '/terms/create') {
                $this->sv->taxonomy->create($r->input('taxonomy'), $r->input('name'));
                $this->audit('term.create', $user);
                return Response::redirect($this->url('/terms'));
            }
            if ($post && preg_match('#^/terms/(\d+)/delete$#', $p, $m)) {
                $this->sv->taxonomy->delete((int)$m[1]);
                $this->audit('term.delete', $user, 'term:' . $m[1]);
                return Response::redirect($this->url('/terms'));
            }
        } catch (LuxException $e) {
            $error = $e->getMessage();
        }
        $csrf = $this->csrf($user);
        $body = '<h1>Categorías y etiquetas</h1>';
        foreach (Taxonomy::KINDS as $kind => $label) {
            $rows = '';
            foreach ($this->sv->taxonomy->all($kind) as $t) {
                $rows .= '<li>' . View::h((string)$t['name']) . ' <small>(' . (int)$t['used'] . ' publicados)</small> '
                    . Ui::form($this->url('/terms/' . (int)$t['id'] . '/delete'), $csrf, Ui::button('Eliminar', 'link'), ['class' => 'inline']) . '</li>';
            }
            $body .= '<h2>' . $label . 's</h2><ul>' . ($rows ?: '<li class="muted">Ninguna todavía.</li>') . '</ul>'
                . Ui::form($this->url('/terms/create'), $csrf, Ui::hidden('taxonomy', $kind) . '<label>Nueva ' . mb_strtolower($label) . '<input name="name" required maxlength="80"></label>' . Ui::button('Añadir'));
        }
        return new Response($error !== null ? 422 : 200, View::page('Categorías y etiquetas', $body, ($this->ctx)($user, $error, null)));
    }

    /* ---------- menús ---------- */

    private function menus(Request $r, array $user, string $p, bool $post, ?string $error = null): Response
    {
        try {
            if ($post && $p === '/menus/add') {
                $target = $r->input('target');
                $url = trim($r->input('url'));
                $this->sv->menus->add($r->input('menu'), $r->input('label'), $target !== '' ? $target : null, $target === '' && $url !== '' ? $url : ($target !== '' ? null : ''));
                $this->audit('menu.add', $user);
                return Response::redirect($this->url('/menus'));
            }
            if ($post && preg_match('#^/menus/(\d+)/(delete|up|down)$#', $p, $m)) {
                $m[2] === 'delete' ? $this->sv->menus->remove((int)$m[1]) : $this->sv->menus->move((int)$m[1], $m[2] === 'up' ? -1 : 1);
                $this->audit('menu.' . $m[2], $user, 'item:' . $m[1]);
                return Response::redirect($this->url('/menus'));
            }
        } catch (LuxException $e) {
            $error = $e->getMessage();
        }
        $csrf = $this->csrf($user);
        $opts = '<option value="">— una dirección —</option>';
        foreach ($this->sv->content->search(null, 'all', '', 200) as $c) {
            $opts .= '<option value="' . View::h((string)$c['uid']) . '">' . View::h((string)$c['title']) . ' (' . View::h($this->sv->types->label((string)$c['type'])) . ')</option>';
        }
        $body = '<h1>Menús</h1><p class="muted">Cada enlace apunta a un contenido por su identificador: si cambias su slug, el menú no se rompe. Los contenidos sin publicar no aparecen en el sitio.</p>';
        foreach (Menus::MENUS as $key => $label) {
            $lis = '';
            foreach ($this->sv->menus->items($key) as $it) {
                $dest = $it['target_uid'] !== null ? 'contenido ' . View::h((string)($this->sv->content->byUid((string)$it['target_uid'])['rev']['title'] ?? '(eliminado)')) : View::h((string)$it['url']);
                $btn = fn(string $a, string $t) => Ui::form($this->url('/menus/' . (int)$it['id'] . '/' . $a), $csrf, Ui::button($t, 'link'), ['class' => 'inline']);
                $lis .= '<li><strong>' . View::h((string)$it['label']) . '</strong> → ' . $dest . ' ' . $btn('up', '↑') . ' ' . $btn('down', '↓') . ' ' . $btn('delete', 'Quitar') . '</li>';
            }
            $body .= '<h2>' . View::h($label) . '</h2><ul>' . ($lis ?: '<li class="muted">Vacío.</li>') . '</ul>'
                . Ui::form($this->url('/menus/add'), $csrf, Ui::hidden('menu', $key)
                    . '<label>Texto<input name="label" required maxlength="60"></label><label>Contenido<select name="target">' . $opts . '</select></label>'
                    . '<label>…o dirección <small>(/ruta o https://…)</small><input name="url" maxlength="500"></label>' . Ui::button('Añadir al menú'));
        }
        return new Response($error !== null ? 422 : 200, View::page('Menús', $body, ($this->ctx)($user, $error, null)));
    }

    /* ---------- ajustes ---------- */

    private function settings(array $user, ?string $error = null, ?string $flash = null): Response
    {
        $csrf = $this->csrf($user);
        $home = $this->sv->settings->get('home_uid');
        $opts = '<option value="">— sin elegir —</option>';
        foreach ($this->sv->content->search('page', 'published', '', 200) as $c) {
            $opts .= '<option value="' . View::h((string)$c['uid']) . '"' . ($c['uid'] === $home ? ' selected' : '') . '>' . View::h((string)$c['title']) . '</option>';
        }
        $body = '<h1>Ajustes del sitio</h1>' . Ui::form($this->url('/settings'), $csrf,
            '<label>Portada <small>(página publicada que se sirve en «/»)</small><select name="home">' . $opts . '</select></label>'
            . '<label>Título del listado del blog<input name="blog_title" maxlength="80" value="' . View::h($this->sv->settings->get('blog_title', 'Blog')) . '"></label>' . Ui::button('Guardar ajustes'))
            . '<h2>Generar el sitio</h2><p>Genera los archivos estáticos con lo publicado y los activa. Los borradores y cambios sin publicar no se incluyen.</p>'
            . Ui::form($this->url('/publish/run'), $csrf, Ui::button('Generar y activar ahora')) . '<p><a href="' . $this->url('/publish') . '">Versiones, reversión y registro de publicaciones</a></p>';
        return new Response($error !== null ? 422 : 200, View::page('Ajustes', $body, ($this->ctx)($user, $error, $flash)));
    }

    private function saveSettings(Request $r, array $user): Response
    {
        $home = $r->input('home');
        $title = trim($r->input('blog_title'));
        if ($home !== '') {
            $c = $this->sv->content->byUid($home);
            if ($c === null || $c['type'] !== 'page' || $c['status'] !== 'published') {
                return $this->settings($user, 'La portada debe ser una página publicada.');
            }
        }
        if (mb_strlen($title) > 80 || preg_match('/[\x00-\x1F]/', $title)) {
            return $this->settings($user, 'El título del blog no es válido.');
        }
        $this->sv->settings->set('home_uid', $home !== '' ? $home : null);
        $this->sv->settings->set('blog_title', $title !== '' ? $title : null);
        $this->audit('settings.save', $user);
        return $this->settings($user, null, 'Ajustes guardados.');
    }

    /* ---------- publicación, versiones y reversión ---------- */

    private function publications(Request $r, array $user, string $p, bool $post): Response
    {
        if ($post && $p === '/publish/run') {
            return $this->runJob($user, $r->input('force') === '1');
        }
        if ($post && preg_match('#^/publish/rollback/([0-9a-f-]+)$#', $p, $m)) {
            try {
                $svc = $this->sv->publishing();
                $id = $svc->enqueueRollback($m[1], 'panel', (int)$user['id'], 'Reversión desde el panel');
                $job = $svc->runNext($id);
                $this->audit('site.rollback', $user, null, ['release' => $m[1], 'job' => $id, 'status' => $job['status'] ?? '?']);
                return Response::redirect($this->url('/publish/jobs/' . $id));
            } catch (LuxException $e) {
                return $this->publicationsPage($user, $e->getMessage());
            }
        }
        if (preg_match('#^/publish/jobs/(\d+)$#', $p, $m)) {
            return $this->jobPage($user, (int)$m[1]);
        }
        if ($p === '/publish') {
            return $this->publicationsPage($user);
        }
        return new Response(404, View::page('No encontrado', '<h1>No encontrado</h1>', ($this->ctx)($user, null, null)));
    }

    private function runJob(array $user, bool $force): Response
    {
        $svc = $this->sv->publishing();
        $id = $svc->enqueuePublish('panel', (int)$user['id'], 'Publicación desde el panel', $force);
        $job = $svc->runNext($id);
        $this->audit('site.publish', $user, null, ['job' => $id, 'status' => $job['status'] ?? 'queued', 'release' => $job['release_id'] ?? null]);
        return Response::redirect($this->url('/publish/jobs/' . $id));
    }

    private static function statusLabel(string $s): string
    {
        return ['queued' => 'En cola', 'running' => 'En curso', 'succeeded' => 'Completado', 'failed' => 'Fallido', 'cancelled' => 'Cancelado'][$s] ?? $s;
    }

    private function jobPage(array $user, int $id): Response
    {
        $job = $this->sv->jobs()->get($id);
        if ($job === null) {
            return new Response(404, View::page('No encontrado', '<h1>Trabajo no encontrado</h1>', ($this->ctx)($user, null, null)));
        }
        $logs = '';
        foreach ($this->sv->jobs()->logs($id) as $l) {
            $logs .= '<li class="log-' . View::h((string)$l['level']) . '"><small>' . Ui::date((int)$l['ts']) . '</small> ' . View::h((string)$l['message']) . '</li>';
        }
        $csrf = $this->csrf($user);
        $retry = $job['status'] === 'failed' || $job['status'] === 'queued' ? Ui::form($this->url('/publish/run'), $csrf, Ui::button('Volver a intentar')) : '';
        $body = '<h1>' . ($job['kind'] === 'rollback' ? 'Reversión' : 'Publicación') . ' #' . $id . '</h1>'
            . '<p><strong>' . View::h(self::statusLabel((string)$job['status'])) . '</strong>'
            . ($job['release_id'] ? ' · versión ' . View::h((string)$job['release_id']) : '')
            . ((int)$job['skipped'] === 1 ? ' · sin cambios (no se generó otra versión)' : '') . '</p>'
            . ($job['error'] ? '<p class="error">' . View::h((string)$job['error']) . '</p>' : '')
            . '<ul class="log">' . ($logs ?: '<li class="muted">Sin registro.</li>') . '</ul>' . $retry
            . '<p><a href="' . $this->url('/publish') . '">← Publicaciones y versiones</a></p>';
        return new Response(200, View::page('Trabajo #' . $id, $body, ($this->ctx)($user, null, null)));
    }

    private function publicationsPage(array $user, ?string $error = null): Response
    {
        $csrf = $this->csrf($user);
        $store = $this->sv->releaseStore();
        $cur = $store->currentId();
        $rules = $this->sv->ruleSync()->lastStatus();
        $body = '<h1>Publicaciones y versiones</h1>'
            . '<p>Versión activa: <strong>' . View::h($cur ?? 'ninguna') . '</strong></p>'
            . Ui::form($this->url('/publish/run'), $csrf, Ui::button('Generar y activar ahora'))
            . Ui::form($this->url('/publish/run'), $csrf, Ui::hidden('force', 1) . Ui::button('Regenerar aunque no haya cambios', 'link'))
            . '<p class="muted">Reglas de redirección del servidor: ' . ($rules['ok'] === null ? 'sin servicio (se usan las páginas-puente y el .htaccess de la versión)' : ($rules['ok'] ? 'aplicadas' : '<strong>rechazadas</strong>: ' . View::h($rules['message'])) . ' · ' . ($rules['time'] > 0 ? Ui::date($rules['time']) : '')) . '</p>'
            . '<h2>Versiones</h2><ul>';
        foreach (array_reverse($store->all()) as $id) {
            $m = $store->meta($id) ?? [];
            $info = ($m['pages'] ?? '?') . ' páginas' . (isset($m['created']) ? ' · ' . View::h((string)$m['created']) : '') . (!empty($m['warnings']) ? ' · ' . count($m['warnings']) . ' avisos' : '');
            $body .= '<li><strong>' . View::h($id) . '</strong>' . ($id === $cur ? ' <em>(activa)</em>' : '') . ' <small>' . $info . '</small> '
                . ($id === $cur ? '' : Ui::form($this->url('/publish/rollback/' . $id), $csrf, Ui::button('Reactivar esta versión', 'link'), ['class' => 'inline'])) . '</li>';
        }
        $body .= ($store->all() === [] ? '<li class="muted">Aún no hay versiones.</li>' : '') . '</ul><h2>Últimos trabajos</h2><ul>';
        foreach ($this->sv->jobs()->recent(10) as $j) {
            $body .= '<li><a href="' . $this->url('/publish/jobs/' . (int)$j['id']) . '">#' . (int)$j['id'] . '</a> '
                . ($j['kind'] === 'rollback' ? 'reversión' : 'publicación') . ' · ' . View::h(self::statusLabel((string)$j['status'])) . ' · <small>' . Ui::date((int)$j['created_at']) . '</small></li>';
        }
        $body .= '</ul>';
        return new Response($error !== null ? 422 : 200, View::page('Publicaciones', $body, ($this->ctx)($user, $error, null)));
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

    private function audit(string $event, array $user, ?string $target = null, array $meta = []): void
    {
        $this->sv->audit->log($event, (int)$user['id'], (string)$user['email'], $target, $meta, $this->ip);
    }
}
