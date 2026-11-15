<?php
declare(strict_types=1);

/** Controlador frontal del panel. Toda la lógica vive en src/Admin/App.php. */

use LuxAeterna\Admin\App;
use LuxAeterna\Admin\Request;
use LuxAeterna\Admin\Response;
use LuxAeterna\Admin\Services;
use LuxAeterna\Core\Autoloader;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\Operation;

$root = dirname(__DIR__, 2);
try {
    require $root . '/src/Core/Autoloader.php';
    Autoloader::register($root . '/src');
    $config = Config::load($root);
    $sv = new Services($config);
    $migrator = new \LuxAeterna\Core\Migrator($sv->db, $root . '/migrations');
    if (!$migrator->isCurrent()) {
        throw new \RuntimeException('Base de datos sin migrar: ejecuta bin/lux migrate');
    }
    (new App($sv))->handle(Request::fromGlobals())->send();
} catch (Throwable $e) {
    $op = Operation::id();
    try {
        (new Logger(isset($config) ? $config->path('data') . '/logs' : $root . '/var/logs'))->error('fallo al iniciar el panel', ['msg' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
    } catch (Throwable) {
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "El panel no está disponible. Referencia de la operación: $op\n";
}
