<?php
// Proceso de publicación aislado para las pruebas de resiliencia. Uso: php worker.php <dir> <layout> <jobId>
declare(strict_types=1);

use CMSModular\Content\ContentLoader;
use CMSModular\Content\SiteSnapshot;
use CMSModular\Core\Config;
use CMSModular\Core\Database;
use CMSModular\Core\Logger;
use CMSModular\Publishing\JobStore;
use CMSModular\Publishing\PublishService;
use CMSModular\Publishing\Publisher;
use CMSModular\Publishing\ReleaseStore;

$root = dirname(__DIR__, 2);
require $root . '/src/Core/Autoloader.php';
CMSModular\Core\Autoloader::register($root . '/src');
[, $d, $layout, $jobId] = $argv;
$c = Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://tu-dominio.es'], 'paths' => ['data' => "$d/var", 'publish' => "$d/pub"], 'publishing' => ['activation' => 'symlink', 'keep_releases' => 5]], $root);
$store = new ReleaseStore("$d/pub");
$log = new Logger("$d/var/logs");
$svc = new PublishService($c, new JobStore(new Database("$d/lux.sqlite")), $store, new Publisher($c, $store, $log, $layout),
    fn() => new SiteSnapshot((new ContentLoader())->load("$root/examples/sitio-demo/content.json"), []), $log);
$j = $svc->runNext((int)$jobId);
echo json_encode(['status' => $j['status'] ?? null]) . "\n";
