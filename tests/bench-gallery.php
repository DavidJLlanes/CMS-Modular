<?php
declare(strict_types=1);

/**
 * Medición de una galería grande (Fase 8): tiempo de publicación, archivos y bytes reales frente a la estimación del panel.
 * Uso: php tests/bench-gallery.php [fotos=60] [megapíxeles=6] [diseño=rejilla]
 */
$root = dirname(__DIR__);
require $root . '/src/Core/Autoloader.php';
CMSModular\Core\Autoloader::register($root . '/src');

$n = (int)($argv[1] ?? 60);
$mp = (float)($argv[2] ?? 6);
$layout = $argv[3] ?? 'rejilla';
$h = (int)round(sqrt($mp * 1000000 / 1.5));
$w = (int)round($h * 1.5);
$dir = sys_get_temp_dir() . '/lux-bg-' . bin2hex(random_bytes(3));
foreach (['db', 'secrets', 'logs'] as $s) {
    mkdir("$dir/$s", 0750, true);
}
$gen = 'mt_srand((int)$argv[4]); $w=(int)$argv[2]; $h=(int)$argv[3]; $im=imagecreatetruecolor($w,$h);
for($y=0;$y<$h;$y+=8)for($x=0;$x<$w;$x+=8)imagefilledrectangle($im,$x,$y,$x+7,$y+7,(int)imagecolorallocate($im,($x*255/$w+mt_rand(0,40))%256,($y*255/$h+mt_rand(0,40))%256,mt_rand(0,255)));
imagejpeg($im,$argv[1],88);';
$c = CMSModular\Core\Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://tu-dominio.es'], 'paths' => ['data' => $dir, 'publish' => "$dir/publish"], 'media' => ['max_pixels' => 60000000]], $root);
$sv = new CMSModular\Admin\Services($c, true);
$uids = [];
$t = microtime(true);
for ($i = 0; $i < $n; $i++) {
    $f = "$dir/f$i.jpg";
    passthru(escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 -r ' . escapeshellarg($gen) . ' ' . escapeshellarg($f) . " $w $h $i");
    $uids[] = $sv->media()->add($f, "foto-$i.jpg", null)['uid'];
}
printf("%d fotos de %d×%d (%.1f MP) subidas en %.1f s\n", $n, $w, $h, $w * $h / 1e6, microtime(true) - $t);
$g = $sv->galleries()->create('Prueba', $layout);
$sv->galleries()->addMedia($g['uid'], $uids);
$sv->galleries()->update($g['uid'], ['lightbox' => ['enabled' => '1', 'captions' => '1']]);
$sv->settings->set('home_uid', 'x');
$id = $sv->content->create('page', ['title' => 'Inicio', 'blocks' => [['type' => 'gallery', 'ref' => $g['uid']]]], 1);
$sv->content->publish($id, 1);
$sv->settings->set('home_uid', (string)$sv->db->val("SELECT uid FROM contents WHERE id = $id"));
$snap = $sv->site->build();
$pub = new CMSModular\Publishing\Publisher($c, new CMSModular\Publishing\ReleaseStore("$dir/publish"), new CMSModular\Core\Logger("$dir/logs"), $root . '/components/layouts/base', $sv->media());
$t = microtime(true);
$pub->publishSnapshot($snap);
$sec = microtime(true) - $t;
$cur = "$dir/publish/current";
$bytes = 0;
$files = 0;
foreach (glob("$cur/media/*.webp") as $x) {
    $bytes += filesize($x);
    $files++;
}
$html = (string)file_get_contents("$cur/index.html");
// lo que descarga un visitante sin abrir el visor: una miniatura (src) por foto + html + css/js del visor
preg_match_all('/<img src="([^"]+)"/', $html, $m);
$shown = 0;
foreach ($m[1] as $u) {
    $shown += (int)@filesize($cur . $u);
}
$assets = 0;
foreach (glob("$cur/assets/c/*") as $x) {
    $assets += filesize($x);
}
$st = $sv->galleries()->snapshot([$g['uid']]);
$s = $sv->mediaSettings();
$img = new CMSModular\Media\ImageRenderer($st['media'], $s->quality(), $s->maxWidth(), 736, null, true);
$gr = new CMSModular\Gallery\GalleryRenderer($st['galleries'], $st['media'], $sv->designs(), "$root/components", $img, static fn(): string => '', true);
$gr->render($g['uid']);
$e = $img->estimate();
printf("Publicación: %.1f s · %d derivados (%.1f MB) · HTML %.0f KB · css+js de galería y visor %.1f KB\n", $sec, $files, $bytes / 1048576, strlen($html) / 1024, $assets / 1024);
printf("Miniaturas visibles (src): %d × media %.0f KB = %.1f MB reales · estimación del panel: %.1f MB visibles, %.1f MB en total (%d archivos)\n", count($m[1]), $shown / max(1, count($m[1])) / 1024, $shown / 1048576, $gr->stats['shown_bytes'] / 1048576, $e['bytes'] / 1048576, $e['files']);
printf("Pico de RAM: %.0f MB\n", (getrusage()['ru_maxrss'] ?? 0) / 1024);
exec('rm -rf ' . escapeshellarg($dir));
