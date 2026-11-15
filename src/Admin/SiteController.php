<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

use LuxAeterna\Content\Menus;
use LuxAeterna\Content\Taxonomy;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Publishing\Publisher;
use LuxAeterna\Publishing\ReleaseStore;
use LuxAeterna\Security\Authorizer;

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
            return $this->need($user, 'site.publish') ?? $this->publishSite($user);
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
            . Ui::form($this->url('/site/publish'), $csrf, Ui::button('Generar y activar ahora'));
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

    private function publishSite(array $user): Response
    {
        try {
            $config = $this->sv->config;
            $publisher = new Publisher($config, new ReleaseStore($config->path('publish')), new Logger($config->path('data') . '/logs'), $config->root . '/components/layouts/base');
            $res = $publisher->publishSnapshot($this->sv->site->build());
            $this->audit('site.publish', $user, null, ['release' => $res['id']]);
            $msg = "Sitio publicado: versión {$res['id']} ({$res['pages']} páginas)." . ($res['warnings'] !== [] ? ' Avisos: ' . implode(' · ', $res['warnings']) : '');
            return $this->settings($user, null, $msg);
        } catch (LuxException $e) {
            return $this->settings($user, $e->getMessage());
        }
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
