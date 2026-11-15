<?php
declare(strict_types=1);

use LuxAeterna\Core\Config;
use LuxAeterna\Core\LuxException;

T::test('config: normaliza la URL base (https, sin barra final)', function () {
    T::eq('https://luxaeterna.es', Config::normalizeBaseUrl('https://LuxAeterna.es/'));
    T::eq('http://localhost:8088', Config::normalizeBaseUrl('http://localhost:8088'));
});
T::test('config: rechaza subcarpeta, http remoto, credenciales y basura', function () {
    foreach (['https://luxaeterna.es/web', 'http://luxaeterna.es', 'https://u:p@luxaeterna.es', 'luxaeterna.es', '', 'https://luxaeterna.es/?a=1', 'ftp://x.es'] as $bad) {
        T::throws(LuxException::class, fn() => Config::normalizeBaseUrl($bad));
    }
});
T::test('config: exige schema y resuelve rutas relativas a la raíz', function () {
    T::throws(LuxException::class, fn() => Config::fromArray(['site' => ['base_url' => 'https://a.es']], '/x'), 'Versión');
    $c = Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://a.es'], 'paths' => ['data' => 'var', 'publish' => '/abs/pub']], '/app');
    T::eq('/app/var', $c->path('data'));
    T::eq('/abs/pub', $c->path('publish'));
    T::eq('x', $c->get('no.existe', 'x'));
    T::throws(LuxException::class, fn() => $c->path('otra'));
});
T::test('config: JSON inválido da error controlado sin ruta', function () {
    $d = T::tmpDir();
    file_put_contents("$d/c.json", '{roto');
    try {
        Config::load($d, "$d/c.json");
        T::ok(false);
    } catch (LuxException $e) {
        T::ok(!str_contains($e->getMessage(), $d));
    }
});
T::test('config: archivo ilegible da un error de permisos, no de JSON', function () {
    $d = T::tmpDir();
    T::throws(LuxException::class, fn() => Config::load($d, "$d/no-existe.json"), 'No hay configuración');
    mkdir("$d/dir.json");
    T::throws(LuxException::class, fn() => Config::load($d, "$d/dir.json"), 'No hay configuración');
});
