<?php
declare(strict_types=1);

use CMSModular\Media\Recipe;

/** Prepara un sitio con portada publicada que usa una imagen subida. @return array{0:\CMSModular\Admin\Services,1:string,2:Client,3:string,4:int} */
function lux_media_site(array $cfg = [], int $w = 1800, int $h = 1200): array
{
    [$sv, $dir] = lux_app(['media' => $cfg]);
    $a = lux_logged($sv, 'a@b.es');
    $up = $a->upload('/admin/media/upload', ['files' => [['Foto de prueba.jpg', lux_img("$dir/foto.jpg", $w, $h)]]], '/admin/media');
    preg_match('#/admin/media/(img-[0-9a-f]{12})#', (string)($up->headers['Location'] ?? ''), $m);
    $uid = $m[1] ?? '';
    $home = lux_new($a, 'Inicio');
    lux_save($a, $home, ['title' => 'Inicio', 'summary' => 'Portada', 'body' => "[imagen:$uid|Una foto de prueba|Pie de foto]\n\nTexto"]);
    lux_act($a, $home, 'publish');
    $a->post('/admin/settings', ['home' => (string)$sv->db->val("SELECT uid FROM contents WHERE id = $home"), 'blog_title' => 'Blog'], '/admin/settings');
    return [$sv, $dir, $a, $uid, $home];
}

function lux_publish_panel(Client $a): string
{
    $job = $a->post('/admin/publish/run', ['force' => '1'], '/admin/publish');
    return $a->get($job->headers['Location'])->body;
}

T::test('panel multimedia: permisos, CSRF y rutas protegidas', function () {
    [$sv, $d] = lux_app();
    $anon = new Client($sv, '203.0.113.60');
    foreach (['/admin/media', '/admin/media/img-0123456789ab', '/admin/media/img-0123456789ab/thumb', '/admin/media/img-0123456789ab/original', '/admin/preview/media/img-0123456789ab-400.0123456789abcdef.webp'] as $p) {
        T::eq(303, $anon->get($p)->status, "sin sesión: $p");
    }
    $a = lux_logged($sv, 'a@b.es');
    T::eq(200, $a->get('/admin/media')->status);
    T::ok($a->req('POST', '/admin/media/upload', ['x' => '1'])->status >= 400, 'sin CSRF no se sube');
    T::eq(0, (int)$sv->db->val('SELECT COUNT(*) FROM media'));
    foreach (['/admin/media/img-zzzz', '/admin/media/../../etc/passwd', '/admin/media/img-0123456789ab/original'] as $p) {
        T::eq(404, $a->get($p)->status, $p);
    }
});
T::test('panel multimedia: subir, ficha, miniatura, original privado y entradas inválidas', function () {
    [$sv, $d] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $r = $a->upload('/admin/media/upload', ['files' => [['IMG_0042 vacaciones.JPG', lux_img("$d/v.jpg", 1200, 800)]]], '/admin/media');
    T::eq(303, $r->status);
    $page = $a->get($r->headers['Location']);
    T::eq(200, $page->status);
    T::contains('1200×800', $page->body);
    T::contains('IMG 0042 vacaciones', $page->body, 'nombre editorial limpio');
    preg_match('#/admin/media/(img-[0-9a-f]{12})#', $r->headers['Location'], $m);
    $uid = $m[1];
    T::contains("[imagen:$uid|", $page->body);
    $t = $a->get("/admin/media/$uid/thumb");
    T::eq('image/webp', $t->headers['Content-Type']);
    T::eq('RIFF', substr($t->body, 0, 4));
    $o = $a->get("/admin/media/$uid/original");
    T::eq(hash_file('sha256', "$d/v.jpg"), hash('sha256', $o->body), 'el original se descarga idéntico');
    T::contains('attachment', $o->headers['Content-Disposition']);
    T::eq('nosniff', $o->headers['X-Content-Type-Options']);
    // la web pública no tiene ninguna ruta a los originales
    T::ok(!str_contains($sv->media()->originalPath((array)$sv->media()->get($uid)), '/publish/'));
    // entradas inválidas: mensaje claro y nada guardado
    file_put_contents("$d/malo.jpg", '<?php phpinfo();');
    $bad = $a->upload('/admin/media/upload', ['files' => [['malo.jpg', "$d/malo.jpg"]]], '/admin/media');
    T::eq(422, $bad->status);
    T::contains('Formato no admitido', $bad->body);
    T::eq(1, (int)$sv->db->val('SELECT COUNT(*) FROM media'));
    // varios archivos a la vez: se suben los buenos y se informa de los malos
    $mix = $a->upload('/admin/media/upload', ['files' => [['a.png', lux_img("$d/a.png", 300, 200, 'png')], ['malo.jpg', "$d/malo.jpg"], ['b.webp', lux_img("$d/b.webp", 300, 200, 'webp')]]], '/admin/media');
    T::eq(200, $mix->status);
    T::contains('2 subida(s)', $mix->body);
    T::contains('1 rechazada(s)', $mix->body);
    T::eq(3, (int)$sv->db->val('SELECT COUNT(*) FROM media'));
    T::contains('IMG 0042', $a->get('/admin/media', ['q' => 'vacaciones'])->body);
    T::contains('Sin resultados', $a->get('/admin/media', ['q' => 'inexistente-xyz'])->body);
    T::eq(3, (int)$sv->db->val("SELECT COUNT(*) FROM audit WHERE event = 'media.upload'"));
});
T::test('panel multimedia: datos editoriales, foco, calidad (solo admin) y reemplazo', function () {
    [$sv, $dir, $a, $uid] = lux_media_site();
    $r = $a->post("/admin/media/$uid/save", ['title' => 'Plaza Mayor', 'alt' => 'Plaza al atardecer', 'caption' => 'Atardecer', 'credit' => 'David J.', 'focus_x' => '30', 'focus_y' => '60'], "/admin/media/$uid");
    T::contains('Cambios guardados', $r->body);
    $m = $sv->media()->get($uid);
    T::eq(['Plaza Mayor', 'Plaza al atardecer', 30, 60], [$m['title'], $m['alt'], (int)$m['focus_x'], (int)$m['focus_y']]);
    $bad = $a->post("/admin/media/$uid/save", ['title' => 'X', 'focus_x' => '300'], "/admin/media/$uid");
    T::eq(422, $bad->status);
    T::contains('foco', $bad->body);
    $ed = lux_logged($sv, 'ed@b.es', 'editor', '198.51.100.40');
    T::eq(403, $ed->req('POST', '/admin/media/settings', ['csrf' => $ed->csrfFrom($ed->get('/admin/media')), 'quality' => '70', 'max_width' => '1600'])->status, 'el editor no cambia la calidad');
    T::eq(200, $ed->get("/admin/media/$uid")->status, 'el editor sí gestiona medios');
    $s = $a->post('/admin/media/settings', ['quality' => '70', 'max_width' => '1400'], '/admin/media');
    T::contains('Ajustes guardados', $s->body);
    T::eq([70, 1400], [$sv->mediaSettings()->quality(), $sv->mediaSettings()->maxWidth()]);
    T::eq(422, $a->post('/admin/media/settings', ['quality' => '2', 'max_width' => '1400'], '/admin/media')->status);
    $rep = $a->upload("/admin/media/$uid/replace", ['file' => [['nueva.png', lux_img("$dir/n.png", 900, 600, 'png')]]], "/admin/media/$uid");
    T::contains('Archivo reemplazado', $rep->body);
    $m2 = $sv->media()->get($uid);
    T::eq([900, 600, 'image/png', 'Plaza Mayor'], [(int)$m2['width'], (int)$m2['height'], $m2['mime'], $m2['title']], 'mismo id y textos, nuevo archivo');
});
T::test('publicación con imágenes: srcset, tamaños sin ampliar, lazy/LCP, WebP en la versión y avisos', function () {
    [$sv, $dir, $a, $uid] = lux_media_site();
    $log = lux_publish_panel($a);
    T::contains('generada y activada', $log);
    $cur = "$dir/publish/current";
    $html = (string)file_get_contents("$cur/index.html");
    T::contains('<figure class="lux-img"><img src="/media/' . $uid . '-', $html);
    T::contains('srcset="', $html);
    T::contains('sizes="(min-width: 736px) 736px, 100vw"', $html);
    T::contains('alt="Una foto de prueba"', $html);
    T::contains('<figcaption>Pie de foto</figcaption>', $html);
    T::contains('fetchpriority="high"', $html, 'la primera imagen es candidata a LCP');
    T::ok(!str_contains($html, 'loading="lazy"'), 'la imagen LCP no se difiere');
    preg_match('/srcset="([^"]+)"/', $html, $m);
    $widths = array_map(static fn($c) => (int)explode(' ', trim($c))[1], explode(',', $m[1]));
    T::eq([400, 800, 1200, 1472], $widths, 'escalera limitada por el uso real (736 px × 2)');
    $files = glob("$cur/media/*.webp");
    T::eq(4, count($files));
    foreach ($files as $f) {
        T::eq('RIFF', file_get_contents($f, false, null, 0, 4));
        T::ok(getimagesize($f)[2] === IMAGETYPE_WEBP);
    }
    T::ok(!is_dir("$cur/originals") && !glob("$cur/*.jpg") && !glob("$cur/media/*.jpg"), 'ningún original en la versión pública');
    preg_match('/<img src="([^"]+)" srcset.*?width="(\d+)" height="(\d+)"/', $html, $s);
    T::eq([800, 533], [(int)$s[2], (int)$s[3]], "width/height del src (variante >= ancho de contenido; evitan saltos de maquetación)");
    T::contains('"' . $uid . '"', (string)file_get_contents("$cur/.lux-media.json"));
});
T::test('publicación con imágenes: avisos de alt, segunda imagen diferida y credenciales del pie', function () {
    [$sv, $dir, $a, $uid, $home] = lux_media_site();
    $a->post("/admin/media/$uid/save", ['title' => 'Foto', 'credit' => 'Ana <b>Ruiz</b>'], "/admin/media/$uid");
    lux_save($a, $home, ['title' => 'Inicio', 'summary' => 'Portada', 'body' => "Texto\n\nOtro texto\n\n[imagen:$uid||]\n\n[imagen:$uid|Segunda|]"]);
    lux_act($a, $home, 'publish');
    $log = lux_publish_panel($a);
    T::contains('Imagen sin texto alternativo', $log, 'aviso editorial (no bloquea)');
    $html = (string)file_get_contents("$dir/publish/current/index.html");
    T::eq(2, substr_count($html, 'loading="lazy"'), 'imágenes fuera de los dos primeros bloques: carga diferida');
    T::ok(!str_contains($html, 'fetchpriority'), 'solo se prioriza una imagen entre los dos primeros bloques');
    T::contains('<small>Ana &lt;b&gt;Ruiz&lt;/b&gt;</small>', $html, 'crédito escapado');
    T::contains('alt=""', $html);
});
T::test('publicación con imágenes: calidad, regeneración desde el original, versiones retenidas y omisión de idénticas', function () {
    [$sv, $dir, $a, $uid] = lux_media_site();
    lux_publish_panel($a);
    $cur = "$dir/publish/current";
    $first = array_map('basename', glob("$cur/media/*.webp"));
    $orig = $sv->media()->originalPath((array)$sv->media()->get($uid));
    $sha = hash_file('sha256', $orig);
    $sizeBefore = array_sum(array_map('filesize', glob("$cur/media/*.webp")));
    // misma receta y misma fuente: publicar otra vez no regenera nada
    $n = (int)$sv->db->val('SELECT COUNT(*) FROM media_derivs');
    $log = lux_publish_panel($a);
    T::eq($n, (int)$sv->db->val('SELECT COUNT(*) FROM media_derivs'), 'derivados vigentes: no se regeneran');
    T::eq($first, array_map('basename', glob("$cur/media/*.webp")));
    // cambiar la calidad: nuevos derivados desde el original, que conserva su huella
    $a->post('/admin/media/settings', ['quality' => '45', 'max_width' => '1600'], '/admin/media');
    lux_publish_panel($a);
    $second = array_map('basename', glob("$cur/media/*.webp"));
    $new = array_values(array_diff($second, $first));
    T::eq(4, count($new), 'huellas nuevas para la nueva calidad');
    T::ok(array_sum(array_map(static fn($f) => filesize("$cur/media/$f"), $new)) < $sizeBefore, 'calidad menor pesa menos');
    T::contains('/media/' . $new[0], (string)file_get_contents("$cur/index.html"), 'la página usa ya los derivados nuevos');
    T::eq($sha, hash_file('sha256', $orig), 'el original conserva su huella');
    // los derivados de la versión anterior siguen disponibles (páginas en caché de visitantes) y la reversión funciona
    $prev = $sv->releaseStore()->all();
    $meta = $sv->releaseStore()->meta($sv->releaseStore()->currentId());
    foreach ($first as $f) {
        T::ok(in_array('/media/' . $f, (array)$meta['assets'], true), "arrastra $f");
        T::ok(is_file("$cur/media/$f"), "sigue en la versión: $f");
    }
    $rb = $a->post('/admin/publish/rollback/' . $prev[count($prev) - 2], [], '/admin/publish');
    T::eq(303, $rb->status, 'reversión aceptada');
    T::contains('activada', $a->get($rb->headers['Location'])->body);
    $html = (string)file_get_contents("$cur/index.html");
    foreach (glob("$cur/media/*.webp") as $f) {
        T::ok(getimagesize($f)[2] === IMAGETYPE_WEBP);
    }
    T::contains('/media/' . $uid . '-', $html);
});
T::test('multimedia: borrar avisa de referencias y no deja contenidos rotos; limpieza de derivados', function () {
    [$sv, $dir, $a, $uid, $home] = lux_media_site();
    $d = $a->get("/admin/media/$uid");
    T::contains('Inicio', $d->body);
    T::contains('publicada y en edición', $d->body);
    T::contains('No se puede borrar', $d->body);
    $del = $a->post("/admin/media/$uid/delete", [], "/admin/media/$uid");
    T::eq(422, $del->status);
    T::ok($sv->media()->get($uid) !== null, 'la imagen sigue existiendo');
    // si se retira del contenido pero la revisión publicada aún la usa, sigue protegida; tras publicar, se puede borrar
    lux_save($a, $home, ['title' => 'Inicio', 'summary' => 'Portada', 'body' => 'Sin imagen']);
    T::contains('publicada', $a->get("/admin/media/$uid")->body);
    lux_act($a, $home, 'publish');
    T::eq([], $sv->media()->references($uid));
    lux_publish_panel($a);
    $sv->media()->ensure((array)$sv->media()->get($uid), [Recipe::make(500)]);
    $count = (int)$sv->db->val('SELECT COUNT(*) FROM media_derivs');
    T::ok($count > 1);
    // limpieza: nada se borra si es reciente o está en uso por una versión retenida
    T::eq(0, $sv->media()->gc([], 7)['removed'], 'periodo de gracia');
    $sv->db->run('UPDATE media_derivs SET last_used_at = ?', [time() - 30 * 86400]);
    $keep = array_keys((array)json_decode((string)file_get_contents("$dir/publish/current/.lux-media.json"), true) ?: []);
    T::eq(0, count($keep), 'la versión actual ya no usa imágenes');
    $dry = $sv->media()->gc([], 7, true);
    T::eq($count, $dry['removed'], 'simulación: informa sin borrar');
    T::eq($count, (int)$sv->db->val('SELECT COUNT(*) FROM media_derivs'));
    $real = $sv->media()->gc([], 7);
    T::eq($count, $real['removed']);
    T::eq(0, count(glob("$dir/media/derived/*.webp")));
    $ok = $a->post("/admin/media/$uid/delete", [], "/admin/media/$uid");
    T::contains('Imagen borrada', $ok->body);
    T::ok(!is_file("$dir/media/originals/" . substr($uid, 4, 2) . "/$uid.jpg"), 'original borrado');
});
T::test('previsualización con imágenes: rutas privadas, receta validada y sin tocar lo público', function () {
    [$sv, $dir, $a, $uid, $home] = lux_media_site();
    $cur = "$dir/publish/current";
    lux_publish_panel($a);
    $before = (string)shell_exec('find ' . escapeshellarg($dir . '/publish') . ' -type f | sort | xargs sha1sum');
    $pv = $a->get("/admin/content/$home/preview", ['frame' => '1']);
    T::eq(200, $pv->status);
    T::contains('/admin/preview/media/' . $uid . '-', $pv->body);
    preg_match('#src="(/admin/preview/media/[^"]+)"#', $pv->body, $m);
    $img = $a->get($m[1]);
    T::eq(200, $img->status);
    T::eq('image/webp', $img->headers['Content-Type']);
    T::eq('RIFF', substr($img->body, 0, 4));
    T::eq(404, $a->get('/admin/preview/media/' . $uid . '-800.0000000000000000.webp')->status, 'una clave inventada no genera nada');
    T::eq(404, $a->get('/admin/preview/media/img-ffffffffffff-800.0000000000000000.webp')->status);
    T::eq($before, (string)shell_exec('find ' . escapeshellarg($dir . '/publish') . ' -type f | sort | xargs sha1sum'), 'la previsualización no escribe en lo publicado');
    // una referencia rota se avisa en la previsualización y bloquea la publicación
    lux_save($a, $home, ['title' => 'Inicio', 'summary' => 'Portada', 'body' => '[imagen:img-000000000000|x|]']);
    T::contains('no encontrada en la biblioteca', $a->get("/admin/content/$home/preview", ['frame' => '1'])->body);
    lux_act($a, $home, 'publish');
    T::contains('ya no existe en la biblioteca', lux_publish_panel($a));
    T::contains('/media/' . $uid . '-', (string)file_get_contents("$cur/index.html"), 'la versión activa no cambió');
});
