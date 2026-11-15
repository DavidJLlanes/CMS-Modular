<?php
// Router solo para pruebas con `php -S`: reproduce lo que hace Nginx (estáticos de /admin/assets y el resto a index.php).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/../public' . $path;
if (str_starts_with($path, '/admin/assets/') && is_file($file)) {
    return false;
}
require __DIR__ . '/../public/admin/index.php';
