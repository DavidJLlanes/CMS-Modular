<?php
declare(strict_types=1);

use LuxAeterna\Core\LuxException;
use LuxAeterna\Design\DesignRegistry;
use LuxAeterna\Gallery\GalleryStore;

/** Sitio con $n fotos subidas, una galería ya creada y una portada que la usa (publicada). @return array{0:\LuxAeterna\Admin\Services,1:string,2:Client,3:list<string>,4:string,5:int} */
function lux_gal_site(int $n = 3, string $layout = 'rejilla', array $lightbox = []): array
{
    [$sv, $dir] = lux_app();
    $a = lux_logged($sv, 'a@b.es');
    $uids = [];
    for ($i = 0; $i < $n; $i++) {
        $up = $a->upload('/admin/media/upload', ['files' => [["Foto $i.jpg", lux_img("$dir/f$i.jpg", 1800 - $i * 100, 1200)]]], '/admin/media');
        preg_match('#/admin/media/(img-[0-9a-f]{12})#', (string)($up->headers['Location'] ?? ''), $m);
        $uids[] = $m[1];
    }
    $g = $sv->galleries();
    $gal = $g->create('Viaje a Asturias', $layout);
    $g->addMedia($gal['uid'], $uids);
    if ($lightbox !== []) {
        $g->update($gal['uid'], ['lightbox' => $lightbox]);
    }
    $home = lux_new($a, 'Inicio');
    lux_save($a, $home, ['title' => 'Inicio', 'summary' => 'Portada', 'body' => "Texto\n\n[galeria:{$gal['uid']}]"]);
    lux_act($a, $home, 'publish');
    $a->post('/admin/settings', ['home' => (string)$sv->db->val("SELECT uid FROM contents WHERE id = $home"), 'blog_title' => 'Blog'], '/admin/settings');
    return [$sv, $dir, $a, $uids, $gal['uid'], $home];
}

T::test('galerías (contrato): los seis diseños y el visor son válidos; el contrato rechaza lo peligroso', function () {
    $comp = lux_components();
    $reg = lux_registry($comp);
    T::eq(['carrusel', 'destacada', 'filas', 'mamposteria', 'mosaico', 'rejilla'], array_keys($reg->byType('gallery')));
    T::eq(['lightbox'], array_keys($reg->byType('viewer')));
    T::eq([], $reg->index()['errors']);
    $bad = [
        'sin bloque gallery' => fn(array $m): array => array_diff_key($m, ['gallery' => 1]),
        'recorte desconocido' => function (array $m): array { $m['gallery']['crops'] = ['circle']; return $m; },
        'recorte por defecto no admitido' => function (array $m): array { $m['gallery']['crop_default'] = 'portrait'; $m['gallery']['crops'] = ['square']; return $m; },
        'miniatura absurda' => function (array $m): array { $m['gallery']['thumb_px'] = 5; return $m; },
        'sizes con caracteres peligrosos' => function (array $m): array { $m['gallery']['sizes'] = '100vw"><script>'; return $m; },
        'sin plantilla de foto' => function (array $m): array { unset($m['item']); return $m; },
    ];
    foreach ($bad as $why => $mut) {
        $c = lux_components();
        lux_clone_design($c, 'rejilla', 'rejilla-mala', $mut);
        T::ok(lux_registry($c)->get('rejilla-mala') === null, "se rechaza: $why");
    }
    $evil = [
        'script en la foto' => ['item.html', '<li><script>1</script>{{{ img }}}</li>'],
        'estilo en línea' => ['item.html', '<li style="x:y">{{{ img }}}</li>'],
        'variable de ítem en la plantilla de carpeta' => ['template.html', '<ul>{{{ img }}}</ul>'],
        'HTML crudo de otro tipo' => ['item.html', '<li>{{{ nav }}}</li>'],
        'enlace remoto' => ['item.html', '<li><a href="https://x.test">{{ alt }}</a></li>'],
    ];
    foreach ($evil as $why => [$file, $html]) {
        $c = lux_components();
        $dst = lux_clone_design($c, 'rejilla', 'rejilla-mala');
        file_put_contents("$dst/$file", $html);
        T::ok(lux_registry($c)->get('rejilla-mala') === null, "se rechaza: $why");
    }
    $c = lux_components();
    $dst = lux_clone_design($c, 'lightbox', 'visor-malo');
    file_put_contents("$dst/viewer.js", 'fetch("/x")');
    T::ok(lux_registry($c)->get('visor-malo') === null, 'el visor no puede hacer peticiones de red');
    $dst = lux_clone_design($c, 'lightbox', 'visor-bueno');
    T::ok(lux_registry($c)->get('visor-bueno') !== null, 'un visor con function(){} es válido');
});
T::test('galerías (almacén): crear, validar ajustes y fotos, orden, foco por foto, referencias y borrado', function () {
    [$sv, $dir, $a, $u, $gid] = lux_gal_site(3);
    $g = $sv->galleries();
    $x = $g->get($gid);
    T::eq(['rejilla', 'square'], [$x['layout'], $x['crop']]);
    T::eq($u, array_column($g->items($gid), 'media_uid'), 'orden de alta');
    $g->setItems($gid, [['media' => $u[2], 'caption' => 'Pie', 'focus_x' => 20], ['media' => $u[0], 'crop' => 'portrait'], ['media' => $u[1]]]);
    $it = $g->items($gid);
    T::eq([$u[2], $u[0], $u[1]], array_column($it, 'media_uid'));
    T::eq([20, 50], [(int)$it[0]['focus_x'], (int)$it[0]['focus_y']], 'un foco a medias se completa con el centro');
    T::eq('portrait', $it[1]['crop']);
    T::throws(LuxException::class, fn() => $g->setItems($gid, [['media' => 'img-000000000000']]), 'no existe');
    T::throws(LuxException::class, fn() => $g->setItems($gid, [['media' => $u[0], 'focus_x' => 101]]), 'foco');
    T::throws(LuxException::class, fn() => $g->setItems($gid, [['media' => $u[0], 'crop' => 'circle']]), 'recorte');
    T::throws(LuxException::class, fn() => $g->setItems($gid, [['media' => $u[0], 'alt' => str_repeat('x', 301)]]), 'alt');
    T::eq(3, count($g->items($gid)), 'un guardado inválido no altera nada');
    T::eq(0, $g->addMedia($gid, [$u[0]]), 'sin duplicados');
    T::throws(LuxException::class, fn() => $g->update($gid, ['layout' => '../../etc']), 'no existe');
    T::throws(LuxException::class, fn() => $g->update($gid, ['options' => ['columnas' => 99]]));
    $g->update($gid, ['layout' => 'mamposteria', 'options' => ['columnas' => '4']]);
    $x = $g->get($gid);
    T::eq(['mamposteria', 'free', 4], [$x['layout'], $x['crop'], $x['options']['columnas']], 'un diseño sin recorte propio fuerza el suyo por defecto');
    $g->update($gid, ['layout' => 'rejilla', 'crop' => 'landscape', 'lightbox' => ['enabled' => '1', 'variant' => 'zoom', 'counter' => '1'], 'protect' => ['nodrag' => '1']]);
    $x = $g->get($gid);
    T::eq(['zoom', true, false, true, false], [$x['lightbox']['variant'], $x['lightbox']['counter'], $x['lightbox']['arrows'], $x['protect']['nodrag'], $x['protect']['noctx']]);
    T::eq(['columnas' => 3, 'espacio' => 8, 'captions' => true], $x['options'], 'al cambiar de diseño se usan los valores por defecto del nuevo');
    T::eq(1, count($g->references($gid)));
    T::throws(LuxException::class, fn() => $g->delete($gid), 'la usan');
    T::throws(LuxException::class, fn() => $sv->media()->delete($u[0]), 'galería');
    $other = $g->create('Vacía', 'rejilla');
    $g->delete($other['uid']);
    T::eq(null, $g->get($other['uid']));
});
T::test('galerías (publicación): miniaturas recortadas con foco, enlaces al visor, recursos solo donde se usan y sin estilos en línea', function () {
    [$sv, $dir, $a, $u, $gid, $home] = lux_gal_site(3, 'rejilla', ['enabled' => '1', 'variant' => 'slide', 'counter' => '1', 'captions' => '1', 'arrows' => '1', 'preload' => '1']);
    $sv->galleries()->setItems($gid, [['media' => $u[0], 'alt' => 'Playa', 'caption' => 'La <playa>', 'focus_x' => 10, 'focus_y' => 90], ['media' => $u[1], 'alt' => 'Monte'], ['media' => $u[2]]]);
    $other = lux_new($a, 'Sin galería');
    lux_save($a, $other, ['title' => 'Sin galería', 'summary' => 'x', 'body' => 'Solo texto']);
    lux_act($a, $other, 'publish');
    $log = lux_publish_panel($a);
    T::contains('generada y activada', $log);
    $cur = "$dir/publish/current";
    $html = (string)file_get_contents("$cur/index.html");
    T::contains('data-lux-gallery="' . $gid . '"', $html);
    T::contains('data-viewer="slide" data-counter data-arrows data-preload', $html);
    T::ok(!str_contains($html, 'data-loop'), 'el bucle estaba desactivado');
    T::contains('role="group" aria-label="Viaje a Asturias"', $html);
    T::contains('<ul class="lux-g lux-g--grid">', $html);
    T::eq(3, substr_count($html, 'class="lux-g-a"'), 'cada foto enlaza a la imagen grande (funciona sin JavaScript)');
    T::contains('data-cap="La &lt;playa&gt;"', $html);
    T::contains('alt="Playa"', $html);
    T::ok(!preg_match('/<[^>]+\sstyle=/i', $html) && !str_contains($html, '<script>'), 'ni estilos ni scripts en línea');
    preg_match('/<img src="(\/media\/[^"]+)" srcset="([^"]+)" sizes="([^"]+)" width="(\d+)" height="(\d+)"/', $html, $m);
    T::eq([400, 400], [(int)$m[4], (int)$m[5]], 'miniatura cuadrada (recorte square) de 400 px');
    T::contains('x400f10-90.', $m[1], 'el nombre del derivado incluye el foco elegido para esa foto');
    T::ok(is_file($cur . $m[1]), 'el derivado está en la versión');
    $im = imagecreatefromwebp($cur . $m[1]);
    T::eq([400, 400], [imagesx($im), imagesy($im)]);
    preg_match('/href="(\/media\/[^"]+)" data-w="(\d+)" data-h="(\d+)" data-srcset="([^"]+)"/', $html, $b);
    T::eq([1600, 1067], [(int)$b[2], (int)$b[3]], 'el visor usa la imagen sin recortar, limitada por el ancho máximo de los ajustes');
    T::ok(is_file($cur . $b[1]));
    // recursos por página
    preg_match_all('#/assets/c/([a-z-]+)\.[0-9a-f]{10}\.(css|js)#', $html, $r);
    T::eq(['lightbox.css', 'lightbox.js', 'rejilla.css'], (function () use ($r): array { $o = array_unique(array_filter(array_map(fn($n, $e) => "$n.$e", $r[1], $r[2]), fn($x) => in_array($x, ['lightbox.css', 'lightbox.js', 'rejilla.css'], true))); sort($o); return array_values($o); })(), 'la portada carga rejilla y el visor');
    T::contains('.js" defer', $html);
    $plain = (string)file_get_contents("$cur/sin-galeria/index.html");
    T::ok(!str_contains($plain, '/assets/c/lightbox') && !str_contains($plain, '/assets/c/rejilla'), 'una página sin galería no carga nada de galería ni del visor');
    $inv = json_decode((string)file_get_contents("$cur/.lux-inventory.json"), true);
    T::ok($inv !== null && isset($inv['modules']['rejilla']) && isset($inv['modules']['lightbox']), 'el inventario lista los módulos usados');
    // sin visor: enlace desactivado y sin JS
    $sv->galleries()->update($gid, ['lightbox' => ['variant' => 'fade']]);
    lux_publish_panel($a);
    $html2 = (string)file_get_contents("$cur/index.html");
    T::ok(!str_contains($html2, 'lux-g-a') && !str_contains($html2, 'data-viewer') && !str_contains($html2, '/assets/c/lightbox'), 'sin visor no hay enlaces ni JavaScript');
});
T::test('galerías (publicación): recortes por diseño, errores claros y avisos editoriales', function () {
    [$sv, $dir, $a, $u, $gid, $home] = lux_gal_site(2, 'mamposteria');
    lux_publish_panel($a);
    $html = (string)file_get_contents("$dir/publish/current/index.html");
    T::contains('lux-g--masonry', $html);
    preg_match('/<img src="([^"]+)"[^>]*width="(\d+)" height="(\d+)"/', $html, $m);
    T::eq([400, 267], [(int)$m[2], (int)$m[3]], 'mampostería conserva la proporción original');
    $sv->galleries()->update($gid, ['layout' => 'filas']);
    $wr = $sv->site->build()->warnings;
    T::ok((bool)array_filter($wr, fn($w) => str_contains($w, 'sin texto alternativo')), 'avisa de fotos sin alt');
    // una foto desaparece de la biblioteca (borrado forzado por SQL): bloquea con mensaje claro
    $sv->db->run('DELETE FROM media WHERE uid = ?', [$u[1]]);
    $e = null;
    try {
        $sv->site->build();
    } catch (LuxException $x) {
        $e = $x->getMessage();
    }
    T::contains('ya no están en la biblioteca', (string)$e);
    $sv->db->run('DELETE FROM galleries WHERE uid = ?', [$gid]);
    $e = null;
    try {
        $sv->site->build();
    } catch (LuxException $x) {
        $e = $x->getMessage();
    }
    T::contains('galería que ya no existe', (string)$e);
});
T::test('galerías (vista previa): mismas plantillas, miniaturas generadas a demanda y rutas validadas', function () {
    [$sv, $dir, $a, $u, $gid, $home] = lux_gal_site(2, 'rejilla', ['enabled' => '1']);
    $sv->galleries()->setItems($gid, [['media' => $u[0], 'focus_x' => 80, 'focus_y' => 20], ['media' => $u[1]]]);
    $html = $sv->preview->html('page', $sv->content->get($home)['rev']);
    T::contains('data-lux-gallery="' . $gid . '"', $html);
    preg_match('#<img src="(/admin/preview/media/[^"]+)"#', $html, $m);
    T::contains('x400f80-20.', $m[1]);
    $name = basename($m[1]);
    [$mime, $bytes] = $sv->preview->image($name);
    T::eq('image/webp', $mime);
    $im = imagecreatefromstring($bytes);
    T::eq([400, 400], [imagesx($im), imagesy($im)]);
    T::eq(null, $sv->preview->image(str_replace('f80-20', 'f81-20', $name)), 'la clave no corresponde a esa receta');
    T::eq(null, $sv->preview->image(str_replace('x400', 'x401', $name)));
    T::eq(200, $a->get($m[1])->status, 'el panel lo sirve a una sesión abierta');
    T::eq(303, (new Client($sv, '203.0.113.70'))->get($m[1])->status, 'y no sin sesión');
});
