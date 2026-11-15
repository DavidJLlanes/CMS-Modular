<?php
declare(strict_types=1);

/**
 * Entrada mínima del panel (Fase 1). Solo confirma que el arranque funciona y que el panel
 * está separado del sitio público. La autenticación llega en la Fase 2: hasta entonces
 * no expone ninguna función ni información del sistema.
 */

use LuxAeterna\Core\Autoloader;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\Operation;

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex, nofollow');

$root = dirname(__DIR__, 2);
try {
    require $root . '/src/Core/Autoloader.php';
    Autoloader::register($root . '/src');
    $config = Config::load($root);
    (new Logger($config->path('data') . '/logs'))->info('acceso al panel (Fase 1)', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    $status = 200;
    $msg = 'Panel de LuxAeterna. La autenticación se incorpora en la Fase 2.';
} catch (Throwable $e) {
    $status = 503;
    $msg = 'El panel no está disponible. Código de operación: ' . Operation::id();
    error_log('LuxAeterna admin: ' . $e->getMessage()); // el detalle va al log del servidor, no al navegador
}
http_response_code($status);
echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="robots" content="noindex"><title>Panel</title>'
    . '<style>body{font:1rem system-ui;margin:3rem auto;max-width:36rem;padding:0 1rem}</style><p>'
    . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></html>';
