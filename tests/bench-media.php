<?php
declare(strict_types=1);

/**
 * Medición de la canalización de imágenes (Fase 6): tiempo y memoria por foto y por lote.
 * Uso: php tests/bench-media.php [megapíxeles=12] [fotos=5] [memory_limit=256M]
 */
$root = dirname(__DIR__);
require $root . '/src/Core/Autoloader.php';
CMSModular\Core\Autoloader::register($root . '/src');

/** RAM máxima real del proceso (GD reserva fuera del gestor de memoria de PHP, así que memory_get_peak_usage no la ve). */
function rss(): float
{
    return (float)(getrusage()['ru_maxrss'] ?? 0) / 1024;
}

$mp = (float)($argv[1] ?? 12);
$n = (int)($argv[2] ?? 5);
$mem = $argv[3] ?? '256M';
$h = (int)round(sqrt($mp * 1000000 / 1.5));
$w = (int)round($h * 1.5);
$dir = sys_get_temp_dir() . '/lux-bench-' . bin2hex(random_bytes(3));
foreach (['db', 'secrets', 'logs', 'src'] as $s) {
    mkdir("$dir/$s", 0750, true);
}
// Fotos sintéticas con ruido (comprimen como una foto real, no como un degradado). Se generan en OTRO proceso
// para que la RAM medida sea solo la del procesado.
$gen = 'mt_srand((int)$argv[4]); $w=(int)$argv[2]; $h=(int)$argv[3]; $im=imagecreatetruecolor($w,$h);
for($y=0;$y<$h;$y+=8)for($x=0;$x<$w;$x+=8)imagefilledrectangle($im,$x,$y,$x+7,$y+7,(int)imagecolorallocate($im,($x*255/$w+mt_rand(0,40))%256,($y*255/$h+mt_rand(0,40))%256,mt_rand(0,255)));
imagejpeg($im,$argv[1],88);';
$files = [];
for ($i = 0; $i < $n; $i++) {
    $files[] = "$dir/foto$i.jpg";
    passthru(escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 -r ' . escapeshellarg($gen) . ' ' . escapeshellarg($files[$i]) . " $w $h $i");
}
ini_set('memory_limit', $mem);
$c = CMSModular\Core\Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://tu-dominio.es'], 'paths' => ['data' => $dir, 'publish' => "$dir/publish"], 'media' => ['max_pixels' => 60000000]], $root);
$sv = new CMSModular\Admin\Services($c, true);
printf("Fotos de %d×%d (%.1f MP, %.1f MB), memory_limit=%s, GD %s\n", $w, $h, $w * $h / 1e6, filesize($files[0]) / 1048576, $mem, GD_VERSION);
$t0 = microtime(true);
$tot = 0;
foreach ($files as $i => $f) {
    $t = microtime(true);
    $m = $sv->media()->add($f, basename($f));
    $ti = microtime(true) - $t;
    $recipes = array_map(static fn($wd) => CMSModular\Media\Recipe::make($wd, 0, 'inside', 50, 50, 82), CMSModular\Media\Recipe::widths($w, 1600, 736));
    $t = microtime(true);
    $d = $sv->media()->ensure($m, $recipes);
    $td = microtime(true) - $t;
    $bytes = array_sum(array_column($d, 'bytes'));
    $tot += $bytes;
    printf("foto %d: subida+miniatura %.2f s · %d derivados en %.2f s (%.0f KB en total) · RAM máxima del proceso %.0f MB\n", $i + 1, $ti, count($d), $td, $bytes / 1024, rss());
}
printf("Lote de %d: %.1f s en total, %.1f s por foto, RAM máxima del proceso %.0f MB, derivados %.1f MB (originales %.1f MB)\n", $n, microtime(true) - $t0, (microtime(true) - $t0) / $n, rss(), $tot / 1048576, $n * filesize($files[0]) / 1048576);
exec('rm -rf ' . escapeshellarg($dir));
