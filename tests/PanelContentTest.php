<?php
declare(strict_types=1);

use CMSModular\Admin\Services;
use CMSModular\Security\Totp;

/** Cliente con sesión completa (usuario nuevo con MFA activo). */
function lux_logged(Services $sv, string $email, string $role = 'admin', string $ip = '203.0.113.7'): Client
{
    [, $secret] = lux_user($sv, $email, $role, true);
    $c = new Client($sv, $ip);
    $c->login($email, PW);
    $c->post('/admin/login/mfa', ['code' => nextCode($secret, 0)], '/admin/login/mfa');
    return $c;
}

/** Crea un contenido por HTTP y devuelve su id. */
function lux_new(Client $c, string $title, string $type = 'page'): int
{
    $r = $c->post('/admin/content/new', ['type' => $type, 'title' => $title], '/admin/content/new');
    preg_match('#/admin/content/(\d+)/edit#', (string)($r->headers['Location'] ?? ''), $m);
    return (int)($m[1] ?? 0);
}

function lux_edit_form(Client $c, int $id): array
{
    $html = $c->get("/admin/content/$id/edit")->body;
    preg_match('/name="version" value="(\d+)"/', $html, $m);
    return ['version' => (int)($m[1] ?? 0), 'html' => $html];
}

function lux_save(Client $c, int $id, array $over = [], ?int $version = null): \CMSModular\Admin\Response
{
    $f = lux_edit_form($c, $id);
    return $c->post("/admin/content/$id/save", $over + ['version' => $version ?? $f['version'], 'title' => 'Sin título', 'slug' => '', 'summary' => '', 'body' => ''], "/admin/content/$id/edit");
}

function lux_act(Client $c, int $id, string $action): \CMSModular\Admin\Response
{
    return $c->post("/admin/content/$id/$action", ['version' => lux_edit_form($c, $id)['version']], "/admin/content/$id/edit");
}

T::test('panel editorial: todas las rutas nuevas exigen sesión (lectura y escritura)', function () {
    [$sv] = lux_app();
    $c = new Client($sv);
    foreach (['/admin/content', '/admin/content/new', '/admin/content/1/edit', '/admin/content/1/revisions', '/admin/content/1/preview', '/admin/preview/style.css', '/admin/terms', '/admin/menus', '/admin/settings'] as $p) {
        $r = $c->get($p);
        T::eq(303, $r->status, $p);
        T::eq('/admin/login', $r->headers['Location'], $p);
    }
    foreach (['/admin/content/new', '/admin/content/1/save', '/admin/content/1/autosave', '/admin/content/1/publish', '/admin/content/1/delete', '/admin/terms/create', '/admin/menus/add', '/admin/settings', '/admin/site/publish'] as $p) {
        T::eq(303, $c->req('POST', $p, ['csrf' => 'x', 'version' => 1])->status, "POST $p");
    }
    $sv->users->create('x@b.es', 'admin');
    T::eq(0, (int)$sv->db->val('SELECT COUNT(*) FROM contents'));
});
T::test('panel editorial: flujo completo crear → editar → revisiones → publicar → papelera → restaurar → borrar', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'admin@b.es');
    $id = lux_new($a, 'Sobre nosotros');
    T::ok($id > 0);
    $r = lux_save($a, $id, ['title' => 'Sobre nosotros', 'slug' => '', 'summary' => 'Quiénes somos', 'body' => "## Historia\n\nTexto uno"]);
    T::eq(303, $r->status);
    $f = lux_edit_form($a, $id);
    T::contains('Texto uno', $f['html']);
    T::contains('ruta pública: /sobre-nosotros/', $f['html']);
    lux_save($a, $id, ['title' => 'Sobre nosotros', 'slug' => '', 'summary' => 'Quiénes somos', 'body' => "## Historia\n\nTexto dos"]);
    $rev = $a->get("/admin/content/$id/revisions")->body;
    T::eq(3, preg_match_all('/<td>#\d+<\/td>/', $rev), 'tres revisiones');
    T::contains('en edición', $rev);
    // restaurar la revisión 2
    $r = $a->post("/admin/content/$id/revisions/2/restore", ['version' => lux_edit_form($a, $id)['version']], "/admin/content/$id/revisions");
    T::eq(303, $r->status);
    T::contains('Texto uno', lux_edit_form($a, $id)['html']);
    T::eq(4, preg_match_all('/<td>#\d+<\/td>/', $a->get("/admin/content/$id/revisions")->body));
    // publicar y ver el estado
    T::eq(303, lux_act($a, $id, 'publish')->status);
    T::contains('Publicado', $a->get('/admin/content')->body);
    lux_save($a, $id, ['title' => 'Sobre nosotros', 'slug' => '', 'summary' => '', 'body' => 'Cambio sin publicar']);
    T::contains('cambios sin publicar', $a->get('/admin/content')->body);
    // papelera
    T::eq(303, lux_act($a, $id, 'trash')->status);
    T::ok(!str_contains($a->get('/admin/content')->body, 'Sobre nosotros'), 'no sale en el listado normal');
    T::contains('Sobre nosotros', $a->get('/admin/content', ['status' => 'trashed'])->body);
    T::contains('Restáuralo para editarlo', $a->get("/admin/content/$id/edit")->body);
    T::eq(422, lux_save($a, $id, ['title' => 'x'])->status, 'no se edita en la papelera');
    T::eq(303, lux_act($a, $id, 'restore')->status);
    T::eq(303, lux_act($a, $id, 'trash')->status);
    T::eq(303, lux_act($a, $id, 'delete')->status);
    T::eq(404, $a->get("/admin/content/$id/edit")->status);
});
T::test('panel editorial: CSRF en todas las mutaciones y token de otra sesión rechazado', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $b = lux_logged($sv, 'b@b.es', 'editor', '198.51.100.9');
    $id = lux_new($a, 'Página');
    $v = lux_edit_form($a, $id)['version'];
    $paths = ["/admin/content/new" => ['type' => 'page', 'title' => 'X'], "/admin/content/$id/save" => ['version' => $v, 'title' => 'Hack'], "/admin/content/$id/publish" => ['version' => $v],
        "/admin/content/$id/trash" => ['version' => $v], "/admin/content/$id/autosave" => ['version' => $v, 'title' => 'Hack'], '/admin/terms/create' => ['taxonomy' => 'tag', 'name' => 'x'],
        '/admin/menus/add' => ['menu' => 'main', 'label' => 'x', 'url' => '/x/'], '/admin/settings' => ['home' => ''], '/admin/site/publish' => []];
    $other = $b->csrfFrom($b->get('/admin/account'));
    foreach ($paths as $p => $data) {
        T::eq(403, $a->req('POST', $p, $data)->status, "sin token: $p");
        T::eq(403, $a->req('POST', $p, $data + ['csrf' => $other])->status, "token ajeno: $p");
        T::eq(403, $a->req('POST', $p, $data + ['csrf' => $a->csrfFrom($a->get('/admin/account'))], ['origin' => 'https://evil.example'])->status, "origen ajeno: $p");
    }
    T::eq(1, (int)$sv->db->val('SELECT COUNT(*) FROM contents'));
    T::eq('Página', $sv->db->val('SELECT title FROM revisions WHERE content_id = ' . $id));
});
T::test('panel editorial: permisos por rol (el editor edita y publica contenido, pero no gestiona menús/ajustes/sitio ni borra)', function () {
    [$sv] = lux_app();
    $e = lux_logged($sv, 'e@b.es', 'editor');
    $id = lux_new($e, 'Mi página');
    T::eq(303, lux_act($e, $id, 'publish')->status, 'el editor publica contenido');
    foreach (['/admin/menus', '/admin/settings'] as $p) {
        T::eq(403, $e->get($p)->status, $p);
    }
    foreach (['/admin/menus/add' => ['menu' => 'main', 'label' => 'x', 'url' => '/x/'], '/admin/settings' => ['home' => ''], '/admin/site/publish' => []] as $p => $d) {
        T::eq(403, $e->post($p, $d, '/admin/account')->status, "POST $p");
    }
    lux_act($e, $id, 'trash');
    T::eq(403, lux_act($e, $id, 'delete')->status, 'borrado definitivo solo admin');
    T::eq(200, $e->get('/admin/terms')->status);
    T::ok(!str_contains($e->get('/admin/')->body, '/admin/menus'), 'el panel no enlaza lo que no puede usar');
    T::eq(1, (int)$sv->db->val('SELECT COUNT(*) FROM contents'));
});
T::test('panel editorial: conflicto de edición (409) conserva el texto y el historial', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $id = lux_new($a, 'Compartida');
    $old = lux_edit_form($a, $id)['version'];
    lux_save($a, $id, ['title' => 'Compartida', 'body' => 'Cambio de la otra pestaña']);
    $r = lux_save($a, $id, ['title' => 'Compartida', 'body' => 'MI-TEXTO-QUE-NO-SE-PIERDE'], $old);
    T::eq(409, $r->status);
    T::contains('MI-TEXTO-QUE-NO-SE-PIERDE', $r->body);
    T::contains('cambió mientras lo editabas', $r->body);
    T::contains('Cambio de la otra pestaña', lux_edit_form($a, $id)['html']);
    $fresh = $a->csrfFrom($r);
    preg_match('/name="version" value="(\d+)"/', $r->body, $m);
    T::ok((int)$m[1] > $old, 'el formulario trae la versión actual para poder reintentar');
    T::eq(303, $a->req('POST', "/admin/content/$id/save", ['csrf' => $fresh, 'version' => $m[1], 'title' => 'Compartida', 'body' => 'MI-TEXTO-QUE-NO-SE-PIERDE'])->status);
    T::eq(1, (int)$sv->db->val("SELECT COUNT(*) FROM revisions WHERE content_id = $id AND blocks LIKE '%Cambio de la otra pestaña%'"), 'la versión ajena sigue en el historial');
});
T::test('panel editorial: autoguardado por JSON, recuperación y conflicto', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $id = lux_new($a, 'Larga');
    $v = lux_edit_form($a, $id)['version'];
    sleep(1);
    $r = $a->post("/admin/content/$id/autosave", ['version' => $v, 'title' => 'Larga', 'body' => 'Texto a medias'], "/admin/content/$id/edit");
    T::eq(200, $r->status);
    T::contains('application/json', $r->headers['Content-Type']);
    T::eq(true, json_decode($r->body, true)['ok']);
    T::eq($v, lux_edit_form($a, $id)['version'], 'no cambia la versión');
    $page = $a->get("/admin/content/$id/edit")->body;
    T::contains('Hay un autoguardado más reciente', $page);
    T::ok(!str_contains($page, 'Texto a medias'), 'no sustituye lo guardado hasta que se recupere');
    T::contains('Texto a medias', $a->get("/admin/content/$id/edit", ['autosave' => '1'])->body);
    $bad = $a->post("/admin/content/$id/autosave", ['version' => $v + 5, 'title' => 'Larga', 'body' => 'x'], "/admin/content/$id/edit");
    T::eq(409, $bad->status);
    T::eq(false, json_decode($bad->body, true)['ok']);
    $bad = $a->post("/admin/content/$id/autosave", ['version' => $v, 'title' => 'Larga', 'body' => "[imagen:../x|a|b]"], "/admin/content/$id/edit");
    T::eq(422, $bad->status);
    T::contains('<script src="/admin/assets/autosave.js" defer>', $page);
    T::contains("script-src 'self'", $a->get("/admin/content/$id/edit")->headers['Content-Security-Policy']);
});
T::test('panel editorial: validación mantiene lo escrito y avisa (slug repetido, reservado, bloque inválido)', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $one = lux_new($a, 'Contacto');
    $two = lux_new($a, 'Otra');
    $r = lux_save($a, $two, ['title' => 'Otra', 'slug' => 'contacto', 'body' => 'MI TEXTO']);
    T::eq(422, $r->status);
    T::contains('Ya existe otro contenido con ese slug', $r->body);
    T::contains('MI TEXTO', $r->body);
    $r = lux_save($a, $two, ['title' => 'Otra', 'slug' => 'admin', 'body' => 'x']);
    T::eq(422, $r->status);
    T::contains('reservado', $r->body);
    $r = lux_save($a, $two, ['title' => 'Otra', 'body' => "[imagen:../../etc|a|b]"]);
    T::eq(422, $r->status);
    T::contains('referencia', $r->body);
    $r = $a->post('/admin/content/new', ['type' => 'page', 'title' => ''], '/admin/content/new');
    T::eq(422, $r->status);
    T::eq(2, (int)$sv->db->val('SELECT COUNT(*) FROM contents'));
});
T::test('panel editorial: previsualización privada con las plantillas reales, noindex y no-store', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $id = lux_new($a, 'Borrador <i>secreto</i>');
    lux_save($a, $id, ['title' => 'Borrador <i>secreto</i>', 'body' => "## Subtítulo\n\nTEXTO-PRIVADO"]);
    $wrap = $a->get("/admin/content/$id/preview", ['mode' => 'mobile']);
    T::eq(200, $wrap->status);
    T::contains('class="pv pv-mobile"', $wrap->body);
    T::contains('sandbox=""', $wrap->body);
    T::contains('class="pv pv-desktop"', $a->get("/admin/content/$id/preview", ['mode' => 'desktop'])->body);
    $frame = $a->get("/admin/content/$id/preview", ['frame' => '1']);
    T::eq(200, $frame->status);
    T::contains('TEXTO-PRIVADO', $frame->body);
    T::contains('<header class="lux-header', $frame->body);
    T::contains('Borrador &lt;i&gt;secreto&lt;/i&gt;', $frame->body);
    T::contains('noindex', $frame->headers['X-Robots-Tag']);
    T::contains('no-store', $frame->headers['Cache-Control']);
    T::contains("frame-ancestors 'self'", $frame->headers['Content-Security-Policy']);
    T::eq('SAMEORIGIN', $frame->headers['X-Frame-Options']);
    T::contains('<meta name="robots" content="noindex,nofollow">', $frame->body);
    $css = $a->get('/admin/preview/style.css');
    T::contains('text/css', $css->headers['Content-Type']);
    T::eq(404, $a->get('/admin/content/999/preview', ['frame' => '1'])->status);
    // una revisión antigua se previsualiza por número
    lux_save($a, $id, ['title' => 'Borrador', 'body' => 'SEGUNDA-VERSION']);
    T::contains('TEXTO-PRIVADO', $a->get("/admin/content/$id/preview/2", ["frame" => "1"])->body);
    // sin sesión no hay previsualización
    T::eq(303, (new Client($sv, '198.51.100.20'))->get("/admin/content/$id/preview", ['frame' => '1'])->status);
});
T::test('panel editorial: todo lo que escribe el usuario se muestra escapado', function () {
    [$sv] = lux_app(['content' => ['types' => ['servicio' => ['label' => 'Servicios', 'fields' => ['nota' => ['type' => 'text', 'label' => 'Nota']]]]]]);
    $a = lux_logged($sv, 'a@b.es');
    $x = '"><script>alert(1)</script>';
    $id = lux_new($a, $x, 'servicio');
    lux_save($a, $id, ['title' => $x, 'summary' => $x, 'body' => $x, 'f_nota' => $x]);
    foreach (["/admin/content/$id/edit", '/admin/content', "/admin/content/$id/revisions", "/admin/content/$id/preview?frame=1"] as $u) {
        [$path, $q] = array_pad(explode('?', $u, 2), 2, '');
        parse_str($q, $query);
        $html = $a->get($path, $query)->body;
        T::ok(!str_contains($html, '<script>alert(1)'), "sin script en $u");
    }
    $a->post('/admin/terms/create', ['taxonomy' => 'tag', 'name' => $x], '/admin/terms');
    T::ok(!str_contains($a->get('/admin/terms')->body, '<script>alert(1)'));
});
T::test('panel editorial: categorías, etiquetas y menús por HTTP, y generación del sitio publicada de extremo a extremo', function () {
    [$sv, $dir] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $home = lux_new($a, 'Inicio');
    lux_save($a, $home, ['title' => 'Inicio', 'body' => 'Bienvenidos']);
    lux_act($a, $home, 'publish');
    // sin portada elegida, generar falla con mensaje claro
    $r = $a->post('/admin/site/publish', [], '/admin/settings');
    T::eq(303, $r->status);
    T::contains('Falta la portada', $a->get($r->headers['Location'])->body);
    T::contains('Fallido', $a->get($r->headers['Location'])->body);
    $uid = (string)$sv->db->val("SELECT uid FROM contents WHERE id = $home");
    T::contains('Ajustes guardados', $a->post('/admin/settings', ['home' => $uid, 'blog_title' => 'Noticias'], '/admin/settings')->body);
    // la portada no se puede retirar ni mandar a la papelera
    T::eq(422, lux_act($a, $home, 'trash')->status);
    T::contains('portada del sitio', lux_act($a, $home, 'unpublish')->body);
    // categoría, etiqueta, entrada
    $a->post('/admin/terms/create', ['taxonomy' => 'category', 'name' => 'Novedades'], '/admin/terms');
    $cat = (int)$sv->db->val("SELECT id FROM terms WHERE slug = 'novedades'");
    $post = lux_new($a, 'Primera noticia', 'post');
    lux_save($a, $post, ['title' => 'Primera noticia', 'summary' => 'Resumen', 'body' => 'Cuerpo de la noticia', 'cats' => [(string)$cat], 'tags' => 'León, Nginx']);
    T::contains('value="León, Nginx"', lux_edit_form($a, $post)['html']);
    lux_act($a, $post, 'publish');
    $a->post('/admin/menus/add', ['menu' => 'main', 'label' => 'Inicio', 'target' => $uid, 'url' => ''], '/admin/menus');
    $a->post('/admin/menus/add', ['menu' => 'main', 'label' => 'Noticias', 'target' => '', 'url' => '/blog/'], '/admin/menus');
    $bad = $a->post('/admin/menus/add', ['menu' => 'main', 'label' => 'Mal', 'target' => '', 'url' => 'javascript:alert(1)'], '/admin/menus');
    T::eq(422, $bad->status);
    T::eq(2, (int)$sv->db->val("SELECT COUNT(*) FROM menu_items"));
    $r = $a->post('/admin/site/publish', [], '/admin/settings');
    T::contains('generada y activada', $a->get($r->headers['Location'])->body);
    $cur = "$dir/publish/current";
    T::contains('Bienvenidos', file_get_contents("$cur/index.html"));
    T::contains('<a href="/blog/">Noticias</a>', file_get_contents("$cur/index.html"));
    T::contains('Primera noticia', file_get_contents("$cur/blog/index.html"));
    T::contains('<title>Noticias</title>', file_get_contents("$cur/blog/index.html"));
    T::contains('Primera noticia', file_get_contents("$cur/categoria/novedades/index.html"));
    T::contains('Cuerpo de la noticia', file_get_contents("$cur/blog/primera-noticia/index.html"));
    T::ok(is_file("$cur/etiqueta/nginx/index.html"));
    T::eq(2, (int)$sv->db->val("SELECT COUNT(*) FROM audit WHERE event = 'site.publish'"));
    // página de publicaciones, reversión desde el panel
    $page = $a->get('/admin/publish')->body;
    T::contains('Publicaciones y versiones', $page);
    T::contains('(activa)', $page);
    $first = $sv->releaseStore()->currentId();
    // borrador posterior no sale
    $draft = lux_new($a, 'Aún no', 'post');
    lux_save($a, $draft, ['title' => 'Aún no', 'body' => 'BORRADOR-NO-SALE']);
    $a->post('/admin/site/publish', [], '/admin/settings');
    T::ok(!is_dir("$cur/blog/aun-no"));
    T::ok($sv->releaseStore()->currentId() === $first, 'sin cambios publicados no se genera otra versión');
    $a->post('/admin/publish/run', ['force' => '1'], '/admin/publish');
    $second = $sv->releaseStore()->currentId();
    T::ok($second !== $first);
    $rb = $a->post("/admin/publish/rollback/$first", [], '/admin/publish');
    T::eq(303, $rb->status);
    T::contains('Completado', $a->get($rb->headers['Location'])->body);
    T::eq($first, $sv->releaseStore()->currentId());
    $rb = $a->post('/admin/publish/rollback/20200101000000000000-abcd', [], '/admin/publish');
    T::contains('Fallido', $a->get($rb->headers['Location'])->body);
    T::eq($first, $sv->releaseStore()->currentId());
    T::eq(403, lux_logged($sv, 'ed@b.es', 'editor', '198.51.100.20')->get('/admin/publish')->status);
    $all = shell_exec('grep -rl BORRADOR-NO-SALE ' . escapeshellarg("$dir/publish") . ' 2>/dev/null');
    T::eq('', trim((string)$all));
});
T::test('panel editorial: la auditoría registra las acciones editoriales', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $id = lux_new($a, 'Auditada');
    lux_save($a, $id, ['title' => 'Auditada', 'body' => 'x']);
    lux_act($a, $id, 'publish');
    $events = array_column($sv->db->all("SELECT event FROM audit WHERE event LIKE 'content.%' ORDER BY id"), 'event');
    T::eq(['content.create', 'content.save', 'content.publish'], $events);
    T::contains('content.publish', $a->get('/admin/audit')->body);
});
