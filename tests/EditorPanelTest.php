<?php
declare(strict_types=1);

/** Contenido rico por HTTP: se guarda como JSON validado, se reabre sin pérdidas y el sitio público no lleva código del editor. */
T::test('editor panel: guardar y reabrir contenido rico sin perder formato; el servidor rechaza HTML activo', function () {
    [$sv, $d] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $id = lux_new($a, 'Rica');
    $blocks = [
        ['type' => 'heading', 'level' => 1, 'text' => 'Título interno'],
        ['type' => 'paragraph', 'align' => 'center', 'style' => ['size' => 'lg'], 'inlines' => [['t' => 'Hola ', 'b' => true], ['t' => 'mundo', 'a' => 'https://e.es/', 'c' => '#ff0000']]],
        ['type' => 'columns', 'layout' => '1-1', 'gap' => 'm', 'valign' => 'top', 'cols' => [[['type' => 'paragraph', 'text' => 'A']], [['type' => 'button', 'label' => 'Ir', 'href' => '/x/', 'variant' => 'outline']]]],
        ['type' => 'table', 'caption' => 'T', 'header_row' => true, 'header_col' => false, 'aligns' => ['left', 'right'], 'rows' => [['a', 'b'], ['c', ['inlines' => [['t' => 'd', 'i' => true]]]]]],
    ];
    $blocks = \LuxAeterna\Content\Blocks::validate($blocks); // forma normalizada
    $r = lux_save($a, $id, ['body_json' => json_encode($blocks)]);
    T::eq(303, $r->status);
    $html = $a->get("/admin/content/$id/edit")->body;
    T::contains('data-lux-editor', $html);
    T::contains('requiere JavaScript', $html, 'contenido rico: sin textarea que lo estropee');
    T::ok(!str_contains($html, '<textarea name="body"'));
    preg_match('/data-body-json value="([^"]*)"/', $html, $m);
    T::eq($blocks, json_decode(html_entity_decode($m[1], ENT_QUOTES), true), 'sin pérdidas');
    // guardar sin cuerpo no borra el contenido rico
    $v = lux_edit_form($a, $id)['version'];
    $a->post("/admin/content/$id/save", ['version' => $v, 'title' => 'Rica', 'slug' => '', 'summary' => ''], "/admin/content/$id/edit");
    T::eq($blocks, json_decode(html_entity_decode((function (string $h): string { preg_match('/data-body-json value="([^"]*)"/', $h, $m); return $m[1]; })($a->get("/admin/content/$id/edit")->body), ENT_QUOTES), true));
    // el servidor valida de nuevo
    foreach ([[['type' => 'html', 'html' => '<script>alert(1)</script>']], [['type' => 'paragraph', 'inlines' => [['t' => 'x', 'a' => 'javascript:alert(1)']]]], 'no-json'] as $bad) {
        $res = lux_save($a, $id, ['body_json' => is_string($bad) ? $bad : json_encode($bad)]);
        T::eq(422, $res->status);
    }
});

T::test('editor panel: contenido sencillo conserva el área de texto como alternativa sin JavaScript', function () {
    [$sv, $d] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $id = lux_new($a, 'Simple');
    lux_save($a, $id, ['body' => "## Hola\n\nTexto"]);
    $html = $a->get("/admin/content/$id/edit")->body;
    T::contains('<textarea name="body"', $html);
    T::ok(!preg_match('/data-body-json[^>]*name=/', $html), 'el JSON no se envía hasta que el editor arranca');
    T::contains('/admin/assets/editor/main.js', $html);
});

T::test('editor panel: endpoints JSON de imágenes (permisos, CSRF, validación)', function () {
    [$sv, $d] = lux_app();
    $anon = new Client($sv, '203.0.113.70');
    T::eq(303, $anon->get('/admin/media/list.json')->status);
    $a = lux_logged($sv, 'a@b.es');
    $up = $a->upload('/admin/media/upload.json', ['files' => [['Foto.jpg', lux_img("$d/f.jpg", 900, 600)]]], '/admin/media');
    T::eq(200, $up->status);
    $j = json_decode($up->body, true);
    T::ok($j['ok'] && preg_match('/^img-[0-9a-f]{12}$/', $j['item']['ref']) === 1);
    $l = json_decode($a->get('/admin/media/list.json', ['q' => 'Foto'])->body, true);
    T::eq(1, $l['total']);
    T::eq($j['item']['ref'], $l['items'][0]['ref']);
    file_put_contents("$d/m.jpg", 'no soy una imagen');
    $bad = $a->upload('/admin/media/upload.json', ['files' => [['m.jpg', "$d/m.jpg"]]], '/admin/media');
    T::eq(422, $bad->status);
    T::ok($a->req('POST', '/admin/media/upload.json', ['x' => '1'])->status >= 400, 'sin CSRF');
});

T::test('editor panel: la vista previa y el sitio publicado usan clases (sin estilos en línea) y no incluyen código del editor', function () {
    [$sv, $d] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $id = lux_new($a, 'Portada');
    lux_save($a, $id, ['title' => 'Portada', 'summary' => 'x', 'body_json' => json_encode([['type' => 'paragraph', 'inlines' => [['t' => 'rojo', 'c' => '#ff0000']]], ['type' => 'columns', 'layout' => '1-1', 'cols' => [[['type' => 'paragraph', 'text' => 'A']], [['type' => 'paragraph', 'text' => 'B']]]]])]);
    $p = $a->get("/admin/content/$id/preview", ['frame' => '1']);
    T::eq(200, $p->status);
    T::contains('lux-c-ff0000', $p->body);
    T::contains('c=ff0000', $p->body);
    $css = $a->get('/admin/preview/style.css', ['u' => 'preview', 't' => 'page', 'c' => 'ff0000'])->body;
    T::contains('.lux-c-ff0000{color:#ff0000}', $css);
    lux_act($a, $id, 'publish');
    $a->post('/admin/settings', ['home' => (string)$sv->db->val("SELECT uid FROM contents WHERE id = $id"), 'blog_title' => 'Blog'], '/admin/settings');
    $out = lux_publish_panel($a);
    $site = (string)$sv->config->get('paths.site', '');
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (str_contains($f->getPathname(), '/releases/') && preg_match('/\.(html|css|js)$/', $f->getFilename())) {
            $files[] = file_get_contents($f->getPathname());
        }
    }
    $all = implode("\n", $files);
    T::ok($files !== [], 'hay una versión publicada');
    T::contains('lux-cols--1-1', $all);
    T::contains('.lux-c-ff0000{color:#ff0000}', $all);
    T::ok(!str_contains($all, 'contenteditable') && !str_contains($all, 'LuxEditor') && !str_contains($all, 'body_json') && !str_contains($all, ' style='), 'ni editor ni estilos en línea');
});
