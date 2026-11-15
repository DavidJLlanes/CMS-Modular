<?php
// Proceso de publicación aislado para las pruebas de resiliencia. Uso: php worker.php <dir> <layout> <jobId>
declare(strict_types=1);

use LuxAeterna\Content\ContentLoader;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Database;
use LuxAeterna\Core\Logger;
use LuxAeterna\Publishing\JobStore;
use LuxAeterna\Publishing\PublishService;
use LuxAeterna\Publishing\Publisher;
use LuxAeterna\Publishing\ReleaseStore;

$root = dirname(__DIR__, 2);
require $root . '/src/Core/Autoloader.php';
LuxAeterna\Core\Autoloader::register($root . '/src');
[, $d, $layout, $jobId] = $argv;
$c = Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://luxaeterna.es'], 'paths' => ['data' => "$d/var", 'publish' => "$d/pub"], 'publishing' => ['activation' => 'symlink', 'keep_releases' => 5]], $root);
$store = new ReleaseStore("$d/pub");
$log = new Logger("$d/var/logs");
$svc = new PublishService($c, new JobStore(new Database("$d/lux.sqlite")), $store, new Publisher($c, $store, $log, $layout),
    fn() => new SiteSnapshot((new ContentLoader())->load("$root/examples/sitio-demo/content.json"), []), $log);
$j = $svc->runNext((int)$jobId);
echo json_encode(['status' => $j['status'] ?? null]) . "\n";
