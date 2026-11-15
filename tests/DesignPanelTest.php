<?php
declare(strict_types=1);

T::test('panel diseño: solo administradores, rutas protegidas y CSRF', function () {
    [$sv] = lux_app();
    $anon = new Client($sv, '203.0.113.50');
    foreach (['/admin/design', '/admin/design/swatch.css', '/admin/design/preview/hero-simple.webp'] as $p) {
        T::eq(303, $anon->get($p)->status, "sin sesión: $p");
    }
    T::eq(403, lux_logged($sv, 'ed@b.es', 'editor', '198.51.100.31')->get('/admin/design')->status);
    $a = lux_logged($sv, 'a@b.es');
    $page = $a->get('/admin/design');
    T::eq(200, $page->status);
    T::contains('Cabecera guay 2', $page->body);
    T::contains('Pie centrado', $page->body);
    T::contains('Catálogo de fuentes', $page->body);
    $bad = $a->req('POST', '/admin/design/save', ['preset' => 'oscuro']);
    T::ok($bad->status >= 400, 'sin CSRF no se guarda');
    T::eq(null, $sv->settings->get('design'));
});
T::test('panel diseño: guardar ranuras, valores, paleta, tipografía y logo; se aplica al publicar', function () {
    [$sv, $dir] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $home = lux_new($a, 'Inicio');
    lux_save($a, $home, ['title' => 'Inicio', 'summary' => 'Portada', 'body' => 'Hola']);
    lux_act($a, $home, 'publish');
    $uid = (string)$sv->db->val("SELECT uid FROM contents WHERE id = $home");
    $a->post('/admin/settings', ['home' => $uid, 'blog_title' => 'Blog'], '/admin/settings');
    $r = $a->post('/admin/design/save', [
        'g' => ['global' => ['header' => ['id' => 'cabeceraguay2', 'values' => ['eslogan' => 'Mi lema', 'fondo' => '#e9dfc9']], 'topbar' => ['id' => 'barra-aviso', 'values' => ['texto' => 'Aviso', 'estilo' => 'suave']], 'hero' => ['id' => '_off'], 'footer' => ['id' => 'footer-centrado', 'values' => ['texto' => 'Pie']]]],
        't' => ['post' => ['hero' => ['id' => 'hero-simple']]],
        'preset' => 'oceano',
        'theme' => ['colors' => ['primary' => '#0b5a80'], 'components' => ['footer' => ['bg' => '#eeeeee', 'text' => '#222222']], 'typography' => ['body' => ['family' => 'inter', 'size' => '18', 'line' => '1.7', 'align' => 'left'], 'heading' => ['family' => 'lora', 'weight' => '700'], 'spacing' => 'normal', 'width' => '50']],
        'logo' => ['mode' => 'text', 'text' => 'Mi Marca', 'sizes' => ['header' => ['180', '120']]],
    ], '/admin/design');
    T::eq(200, $r->status, $r->body);
    T::contains('Diseño guardado', $r->body);
    $saved = json_decode((string)$sv->settings->get('design'), true);
    T::eq('cabeceraguay2', $saved['global']['header']['id']);
    T::eq('#e9dfc9', $saved['global']['header']['values']['fondo']);
    T::eq('hero-simple', $saved['types']['post']['hero']['id']);
    T::eq('#0b5a80', $saved['theme']['colors']['primary']);
    T::eq([180, 120], $saved['logo']['sizes']['header']);
    T::eq(1, (int)$sv->db->val("SELECT COUNT(*) FROM audit WHERE event = 'design.save'"));
    // se aplica al publicar y el inventario aparece en el panel
    $job = $a->post('/admin/publish/run', [], '/admin/publish');
    T::contains('generada y activada', $a->get($job->headers['Location'])->body);
    $cur = "$dir/publish/current";
    $html = file_get_contents("$cur/index.html");
    T::contains('Mi lema', $html);
    T::contains('lux-topbar--suave', $html);
    T::contains('Mi Marca', $html);
    T::contains('lux-footer2', $html);
    T::ok(count(glob("$cur/assets/fonts/*.woff2")) === 4);
    T::contains('Recursos por página', $a->get('/admin/design')->body);
});
T::test('panel diseño: valores no válidos se rechazan con mensaje y no se guardan', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    foreach ([
        ['theme' => ['colors' => ['bg' => 'blanco']]],
        ['theme' => ['typography' => ['body' => ['size' => '99']]]],
        ['theme' => ['typography' => ['body' => ['family' => 'inventada']]]],
        ['g' => ['global' => ['header' => ['id' => 'footer-basico']]]],
        ['g' => ['global' => ['header' => ['id' => 'cabeceraguay2', 'values' => ['fondo' => 'rojo']]]]],
        ['logo' => ['mode' => 'image']],
        ['logo' => ['sizes' => ['header' => ['100', '400']]]],
        ['preset' => 'no-existe'],
    ] as $i => $post) {
        $r = $a->post('/admin/design/save', $post, '/admin/design');
        T::eq(422, $r->status, "caso $i");
    }
    T::eq(null, $sv->settings->get('design'));
});
T::test('panel diseño: comprobación de contraste sin guardar y hoja de muestras segura', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $r = $a->post('/admin/design/save', ['check' => '1', 'theme' => ['colors' => ['text' => '#cccccc']]], '/admin/design');
    T::eq(200, $r->status);
    T::contains('por debajo de 4,5:1', $r->body);
    T::contains('valores sin guardar', $r->body);
    T::eq(null, $sv->settings->get('design'), 'no guarda');
    T::contains('cccccc-ffffff', $r->body);
    $css = $a->get('/admin/design/swatch.css', ['c' => 'cccccc-ffffff,zzzzzz-000000,000000-ffffff;}body{display:none']);
    T::contains('.sw-0{color:#cccccc;background:#ffffff', $css->body);
    T::ok(!str_contains($css->body, 'display:none') && !str_contains($css->body, 'zzzzzz'), 'solo pares hexadecimales');
    T::contains('text/css', $css->headers['Content-Type']);
});
T::test('panel diseño: vista previa WebP, redetección y errores de contrato visibles', function () {
    [$sv] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $img = $a->get('/admin/design/preview/hero-simple.webp');
    T::eq(200, $img->status);
    T::eq('image/webp', $img->headers['Content-Type']);
    T::eq(404, $a->get('/admin/design/preview/no-existe.webp')->status);
    T::eq(404, $a->get('/admin/design/preview/..%2f..%2fconfig.webp')->status);
    T::eq(303, $a->post('/admin/design/refresh', [], '/admin/design')->status);
    T::eq(1, (int)$sv->db->val("SELECT COUNT(*) FROM audit WHERE event = 'design.refresh'"));
});
T::test('panel diseño: la previsualización usa los mismos diseños, fuentes y tema (privado y sin escribir en publish)', function () {
    [$sv, $dir] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $a->post('/admin/design/save', ['g' => ['global' => ['header' => ['id' => 'cabeceraguay2', 'values' => ['eslogan' => 'Lema preview', 'fondo' => '#112233']]]], 'theme' => ['typography' => ['body' => ['family' => 'inter', 'size' => '17', 'line' => '1.6', 'align' => 'left'], 'heading' => ['family' => 'lora', 'weight' => '700'], 'spacing' => 'normal', 'width' => '46']]], '/admin/design');
    $id = lux_new($a, 'Vista');
    lux_save($a, $id, ['title' => 'Vista', 'body' => 'Texto']);
    $uid = (string)$sv->db->val("SELECT uid FROM contents WHERE id = $id");
    $frame = $a->get("/admin/content/$id/preview", ['frame' => '1']);
    T::contains('Lema preview', $frame->body);
    T::contains("font-src 'self'", $frame->headers['Content-Security-Policy']);
    T::contains("href=\"/admin/preview/style.css?u=$uid&amp;t=page\"", $frame->body);
    $css = $a->get('/admin/preview/style.css', ['u' => $uid, 't' => 'page']);
    T::contains('--lux-font-heading', $css->body);
    T::contains('.lux-i-', $css->body);
    T::contains('/admin/preview/font/inter/inter-latin-400-normal.woff2', $css->body);
    $font = $a->get('/admin/preview/font/inter/inter-latin-400-normal.woff2');
    T::eq(200, $font->status);
    T::eq('font/woff2', $font->headers['Content-Type']);
    T::eq('*', $font->headers['Access-Control-Allow-Origin']);
    T::eq(404, $a->get('/admin/preview/font/inter/otro.woff2')->status);
    T::eq(404, $a->get('/admin/preview/logo/no-existe.png')->status);
    T::ok(!is_dir("$dir/publish/current"), 'la previsualización no escribe en publish/');
});
