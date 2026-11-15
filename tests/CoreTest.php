<?php
declare(strict_types=1);

use LuxAeterna\Core\Config;
use LuxAeterna\Core\Doctor;
use LuxAeterna\Core\Installer;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\Operation;

T::test('logger: JSON por líneas con identificador de operación', function () {
    $d = T::tmpDir();
    (new Logger("$d/logs"))->error('fallo', ['x' => 1]);
    $line = trim((string)file_get_contents(glob("$d/logs/*.log")[0]));
    $j = json_decode($line, true);
    T::eq('error', $j['level']);
    T::eq(Operation::id(), $j['op']);
    T::eq(8, strlen($j['op']));
});
T::test('instalador: idempotente, crea directorios privados y conserva la configuración', function () {
    $d = T::tmpDir();
    mkdir("$d/config", 0755);
    copy(dirname(__DIR__) . '/config/lux.example.json', "$d/config/lux.example.json");
    $i = new Installer($d);
    $i->install('https://prueba.es');
    foreach (Doctor::DATA_SUBDIRS as $s) {
        T::ok(is_dir("$d/var/$s"), $s);
    }
    T::ok(is_file("$d/var/.htaccess"));
    T::ok(is_dir("$d/publish/releases"));
    $before = file_get_contents("$d/config/lux.json");
    $out = $i->install('https://otra.es');
    T::eq($before, file_get_contents("$d/config/lux.json"), 'no sobrescribe sin --force');
    T::ok(str_contains(implode(' ', $out), 'conservada'));
    $i->install('https://otra.es', true);
    T::eq('https://otra.es', Config::load($d)->baseUrl());
});
T::test('doctor: sin errores en un entorno instalado y detecta datos dentro de public/', function () {
    $d = T::tmpDir();
    mkdir("$d/config", 0755);
    mkdir("$d/public", 0755);
    copy(dirname(__DIR__) . '/config/lux.example.json', "$d/config/lux.example.json");
    (new Installer($d))->install('https://prueba.es');
    $r = (new Doctor($d, Config::load($d)))->run();
    T::eq(0, Doctor::exitCode($r), json_encode($r));
    $bad = Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://prueba.es'], 'paths' => ['data' => "$d/public/datos", 'publish' => "$d/publish"]], $d);
    mkdir("$d/public/datos", 0755);
    $r = (new Doctor($d, $bad))->run();
    T::eq(1, Doctor::exitCode($r));
    T::ok(str_contains(json_encode($r, JSON_UNESCAPED_SLASHES), 'dentro de public/'));
});
T::test('doctor: sin configuración falla con mensaje claro', function () {
    $r = (new Doctor('/tmp', null))->run();
    T::eq(1, Doctor::exitCode($r));
});
