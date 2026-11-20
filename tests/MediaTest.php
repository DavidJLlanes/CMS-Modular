<?php
declare(strict_types=1);

use CMSModular\Core\LuxException;
use CMSModular\Media\DerivativeBuilder;
use CMSModular\Media\ImageInspector;
use CMSModular\Media\MediaSettings;
use CMSModular\Media\Recipe;

/** Imagen de prueba: degradado con un recuadro rojo (para comprobar recortes y orientación). */
function lux_img(string $file, int $w, int $h, string $type = 'jpg', array $o = []): string
{
    $im = imagecreatetruecolor($w, $h);
    if (!empty($o['alpha'])) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int)imagecolorallocatealpha($im, 10, 20, 30, 127));
    } else {
        for ($x = 0; $x < $w; $x += max(1, intdiv($w, 64))) {
            imagefilledrectangle($im, $x, 0, $x + max(1, intdiv($w, 64)), $h, (int)imagecolorallocate($im, ($x * 255) % 256, 90, 200 - ($x % 100)));
        }
    }
    imagefilledrectangle($im, 0, 0, intdiv($w, 5), intdiv($h, 5), (int)imagecolorallocate($im, 255, 0, 0)); // esquina superior izquierda roja
    match ($type) {
        'jpg' => imagejpeg($im, $file, 90),
        'png' => imagepng($im, $file),
        'webp' => imagewebp($im, $file, 80),
        'gif' => imagegif($im, $file),
    };
    imagedestroy($im);
    if (isset($o['orientation'])) { // EXIF mínimo con Orientation + un comentario "privado" que debe desaparecer
        $tiff = "II*\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00" . pack('v', $o['orientation']) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        $com = 'SECRETO-GPS-40.0,-5.7';
        $j = (string)file_get_contents($file);
        $j = substr($j, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . "\xFF\xFE" . pack('n', strlen($com) + 2) . $com . substr($j, 2);
        file_put_contents($file, $j);
    }
    return $file;
}

function lux_media(array $cfg = []): array
{
    [$sv, $d] = lux_app(['media' => $cfg]);
    return [$sv, $sv->media(), $d];
}

T::test('media: la validación mira el contenido, no el nombre ni el tipo declarado', function () {
    [$sv, $store, $d] = lux_media(['max_pixels' => 1500000, 'max_upload_mb' => 1]);
    $s = $sv->mediaSettings();
    foreach (['jpg', 'png', 'webp', 'gif'] as $t) {
        $f = lux_img("$d/ok.$t", 120, 80, $t);
        $i = ImageInspector::inspect($f, $s);
        T::eq([120, 80], [$i['width'], $i['height']], $t);
    }
    file_put_contents("$d/falso.jpg", "<?php echo 'hola'; ?>");
    T::throws(LuxException::class, fn() => ImageInspector::inspect("$d/falso.jpg", $s), 'Formato no admitido');
    file_put_contents("$d/vacio.png", '');
    T::throws(LuxException::class, fn() => ImageInspector::inspect("$d/vacio.png", $s), 'vacío');
    file_put_contents("$d/trunc.jpg", substr((string)file_get_contents(lux_img("$d/b.jpg", 300, 300)), 0, 200));
    T::throws(LuxException::class, fn() => $store->add("$d/trunc.jpg", 'trunc.jpg'));
    T::eq(0, (int)$sv->db->val('SELECT COUNT(*) FROM media'), 'una entrada manipulada no deja registro');
    T::throws(LuxException::class, fn() => ImageInspector::inspect(lux_img("$d/grande.png", 1400, 1200, 'png'), $s), 'megapíxeles');
    file_put_contents("$d/pesado.png", str_repeat('A', 1100000));
    T::throws(LuxException::class, fn() => ImageInspector::inspect("$d/pesado.png", $s), 'pesa');
});
T::test('media: formatos animados y archivos polimorfos se rechazan con mensaje claro', function () {
    [$sv, $store, $d] = lux_media();
    $s = $sv->mediaSettings();
    // GIF animado de 1×1 con dos fotogramas (cada uno con su bloque de control gráfico).
    $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00" . "\x02\x02\x44\x01\x00";
    file_put_contents("$d/anim.gif", "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF" . $frame . $frame . ';');
    T::throws(LuxException::class, fn() => ImageInspector::inspect("$d/anim.gif", $s), 'animadas');
    // PNG con chunk acTL (APNG).
    $p = (string)file_get_contents(lux_img("$d/p.png", 20, 20, 'png'));
    $at = pack('N', 8) . 'acTL' . pack('NN', 2, 0);
    file_put_contents("$d/apng.png", substr($p, 0, 33) . $at . pack('N', crc32('acTL' . pack('NN', 2, 0))) . substr($p, 33));
    T::throws(LuxException::class, fn() => ImageInspector::inspect("$d/apng.png", $s), 'animadas');
    // JPEG con código PHP al final: se acepta como imagen, pero el derivado se vuelve a codificar y no lo conserva.
    $j = lux_img("$d/poly.jpg", 80, 60);
    file_put_contents($j, "<?php system('id'); ?>", FILE_APPEND);
    $m = $store->add($j, 'poly.jpg');
    $der = $store->ensure($m, [Recipe::make(64)]);
    $bytes = (string)file_get_contents(array_values($der)[0]['file']);
    T::ok(!str_contains($bytes, '<?php') && str_starts_with($bytes, 'RIFF'), 'el derivado es WebP limpio');
});
T::test('media: el original se guarda fuera de la web con identificador generado y no se altera', function () {
    [$sv, $store, $d] = lux_media();
    @mkdir("$d/Mi Foto_con-ñ ..", 0755, true);
    $src = lux_img("$d/Mi Foto_con-ñ ../raro.jpg", 640, 480);
    $sha = hash_file('sha256', $src);
    $m = $store->add($src, 'Mi Foto_con-ñ ../raro.jpg', 1);
    T::ok((bool)preg_match('/^img-[0-9a-f]{12}$/', (string)$m['uid']));
    T::eq('raro', $m['title'], 'título editorial desde el nombre (sin rutas)');
    $orig = $store->originalPath($m);
    T::ok(str_starts_with($orig, "$d/media/originals/"), 'bajo var/media');
    T::ok(!str_contains($orig, 'raro') && !str_contains($orig, 'Foto'), 'no usa el nombre subido');
    T::eq($sha, hash_file('sha256', $orig), 'original intacto');
    T::eq($sha, $m['sha256']);
    $dup = $store->add($src, 'otra.jpg');
    T::ok($dup['duplicate'] && $dup['uid'] === $m['uid'], 'mismo contenido = mismo registro');
    T::eq(1, (int)$sv->db->val('SELECT COUNT(*) FROM media'));
});
T::test('media: derivados WebP, sin ampliar, sin metadatos y con orientación aplicada', function () {
    [$sv, $store, $d] = lux_media();
    $m = $store->add(lux_img("$d/o.jpg", 600, 400, 'jpg', ['orientation' => 6]), 'giro.jpg');
    T::eq([400, 600], [(int)$m['width'], (int)$m['height']], 'dimensiones tras la orientación');
    T::contains('orientación', (string)$m['notes']);
    $r = Recipe::make(1000);
    $der = $store->ensure($m, [$r, Recipe::make(200)]);
    T::eq(2, count($der));
    [$big, $small] = array_values($der);
    T::eq([400, 600], [$big['width'], $big['height']], 'no se amplía por encima del original');
    T::eq([200, 300], [$small['width'], $small['height']]);
    $bytes = (string)file_get_contents($big['file']);
    T::ok(str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP');
    T::ok(!str_contains($bytes, 'SECRETO') && !str_contains($bytes, 'Exif'), 'metadatos privados eliminados');
    $im = imagecreatefromwebp($big['file']);
    $c = imagecolorsforindex($im, imagecolorat($im, 395, 5));
    T::ok($c['red'] > 200 && $c['green'] < 80, 'la esquina roja pasa de arriba-izquierda a arriba-derecha (giro de 90° a la derecha)');
    $info = getimagesize($big['file']);
    T::eq(IMAGETYPE_WEBP, $info[2]);
});
T::test('media: recetas con huella; no se regenera lo vigente y cambiar calidad regenera desde el original', function () {
    [$sv, $store, $d] = lux_media();
    $m = $store->add(lux_img("$d/c.jpg", 900, 600), 'c.jpg');
    $a = $store->ensure($m, [Recipe::make(600, 0, 'inside', 50, 50, 82)]);
    $keyA = array_key_first($a);
    $mtime = filemtime($a[$keyA]['file']);
    $n = (int)$sv->db->val('SELECT COUNT(*) FROM media_derivs');
    sleep(1);
    $again = $store->ensure($m, [Recipe::make(600, 0, 'inside', 50, 50, 82)]);
    T::eq($keyA, array_key_first($again));
    T::eq($mtime, filemtime($again[$keyA]['file']), 'misma receta y misma fuente: no se vuelve a escribir');
    T::eq($n, (int)$sv->db->val('SELECT COUNT(*) FROM media_derivs'));
    $b = $store->ensure($m, [Recipe::make(600, 0, 'inside', 50, 50, 50)]);
    T::ok(array_key_first($b) !== $keyA, 'otra calidad = otra huella');
    T::ok($b[array_key_first($b)]['bytes'] < $a[$keyA]['bytes'], 'calidad menor, archivo menor');
    T::eq(Recipe::key($m['sha256'], Recipe::make(600, 0, 'inside', 50, 50, 82)), $keyA, 'clave determinista');
    // Cambiar el original (reemplazo) cambia todas las huellas, y el original anterior desaparece.
    $old = $store->originalPath($m);
    $m2 = $store->replace((string)$m['uid'], lux_img("$d/c2.png", 700, 500, 'png'));
    T::eq($m['uid'], $m2['uid']);
    T::ok(!is_file($old) && is_file($store->originalPath($m2)), 'el original se sustituye');
    $c = $store->ensure($m2, [Recipe::make(600, 0, 'inside', 50, 50, 82)]);
    T::ok(array_key_first($c) !== $keyA, 'nuevo original, nuevas huellas');
});
T::test('media: recorte con foco y transparencia', function () {
    [$sv, $store, $d] = lux_media();
    $m = $store->add(lux_img("$d/w.jpg", 800, 400), 'w.jpg');
    $left = array_values($store->ensure($m, [Recipe::make(200, 200, 'cover', 0, 50)]))[0];
    $right = array_values($store->ensure($m, [Recipe::make(200, 200, 'cover', 100, 50)]))[0];
    T::eq([200, 200], [$left['width'], $left['height']]);
    $l = imagecreatefromwebp($left['file']);
    $r = imagecreatefromwebp($right['file']);
    $cl = imagecolorsforindex($l, imagecolorat($l, 5, 5));
    $cr = imagecolorsforindex($r, imagecolorat($r, 5, 5));
    T::ok($cl['red'] > 200 && $cr['red'] < 200, 'foco a la izquierda conserva el recuadro rojo; foco a la derecha, no');
    $p = $store->add(lux_img("$d/t.png", 100, 100, 'png', ['alpha' => true]), 't.png');
    T::eq(1, (int)$p['has_alpha']);
    $t = array_values($store->ensure($p, [Recipe::make(80)]))[0];
    $im = imagecreatefromwebp($t['file']);
    $a = (imagecolorat($im, 70, 70) >> 24) & 0x7F;
    T::ok($a > 100, 'la transparencia se conserva');
});
T::test('media: límites de memoria y calidad', function () {
    [$sv, $store, $d] = lux_media();
    $old = ini_get('memory_limit');
    ini_set('memory_limit', '40M');
    try {
        T::throws(LuxException::class, fn() => ImageInspector::checkMemory(6000, 4000), 'memoria');
    } finally {
        ini_set('memory_limit', $old);
    }
    T::throws(LuxException::class, fn() => MediaSettings::validate(['quality' => 5, 'max_width' => 1600]), 'calidad');
    T::throws(LuxException::class, fn() => MediaSettings::validate(['quality' => 80, 'max_width' => 100]), 'ancho');
    T::eq([400, 800, 1200, 1472], Recipe::widths(3000, 1600, 736));
    T::eq([400, 800, 1000], Recipe::widths(1000, 1600, 736));
    T::eq([300], Recipe::widths(300, 1600, 736), 'una foto pequeña no se amplía');
});
T::test('media: verificación de originales (restauración) y claves en uso por versiones retenidas', function () {
    [$sv, $store, $d] = lux_media();
    $a = $store->add(lux_img("$d/a.jpg", 200, 100), 'a.jpg');
    $b = $store->add(lux_img("$d/b2.png", 210, 100, 'png'), 'b.png');
    T::eq(0, count($sv->media()->verify()['missing']));
    unlink($store->originalPath($a));
    file_put_contents($store->originalPath($b), 'otra cosa');
    file_put_contents(dirname($store->originalPath($b)) . '/huerfano.jpg', 'x');
    $v = $store->verify();
    T::eq([[$a['uid']], [$b['uid']], 1], [$v['missing'], $v['corrupt'], count($v['orphans'])]);
    T::eq([], $sv->mediaKeepKeys(), 'sin versiones, nada que conservar');
});
