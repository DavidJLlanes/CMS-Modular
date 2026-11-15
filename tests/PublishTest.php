<?php
declare(strict_types=1);

use LuxAeterna\Core\Config;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Publishing\Activator;
use LuxAeterna\Publishing\Preflight;
use LuxAeterna\Publishing\Publisher;
use LuxAeterna\Publishing\ReleaseStore;

$ctx = function (string $mode, int $keep = 5): array {
    $d = T::tmpDir();
    $root = dirname(__DIR__);
    $c = Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://luxaeterna.es'], 'paths' => ['data' => "$d/var", 'publish' => "$d/pub"], 'publishing' => ['activation' => $mode, 'keep_releases' => $keep]], $root);
    $store = new ReleaseStore("$d/pub");
    $pub = new Publisher($c, $store, new Logger("$d/var/logs"), $root . '/components/layouts/base');
    return [$d, $c, $store, $pub, $root . '/examples/sitio-demo/content.json'];
};

foreach (['symlink', 'rename'] as $mode) {
    T::test("publicación [$mode]: genera index.html y activa", function () use ($ctx, $mode) {
        [$d, , $store, $pub, $content] = $ctx($mode);
        $r = $pub->publish($content, true);
        T::eq($r['id'], $store->currentId());
        T::ok(is_file("$d/pub/current/index.html"));
        T::ok(is_file("$d/pub/current/sobre-nosotros/index.html"));
        T::ok(is_file("$d/pub/current/404.html"));
        T::eq($mode, $r['strategy']);
        $html = file_get_contents("$d/pub/current/sobre-nosotros/index.html");
        T::ok(str_contains($html, 'rel="canonical" href="https://luxaeterna.es/sobre-nosotros/"'));
        T::ok(str_contains($html, '&lt;etiquetas&gt; &amp;'), 'texto escapado');
        T::ok(!str_contains($html, '<etiquetas>'));
    });
    T::test("publicación [$mode]: segunda versión y reversión", function () use ($ctx, $mode) {
        [$d, $c, $store, $pub, $content] = $ctx($mode);
        $a = $pub->publish($content, true)['id'];
        $b = $pub->publish($content, true)['id'];
        T::eq($b, $store->currentId());
        T::eq([$a, $b], $store->all());
        (new Activator($store, $mode))->activate($a);
        T::eq($a, $store->currentId());
        T::ok(is_file("$d/pub/current/index.html"));
        (new Activator($store, $mode))->activate($b);
        T::eq($b, $store->currentId());
    });
    T::test("publicación [$mode]: contenido inválido deja el sitio activo intacto", function () use ($ctx, $mode) {
        [$d, , $store, $pub, $content] = $ctx($mode);
        $ok = $pub->publish($content, true)['id'];
        file_put_contents("$d/malo.json", '{"schema":1,"pages":[{"id":"a","slug":"x","title":"X","body":[]}]}');
        T::throws(LuxException::class, fn() => $pub->publish("$d/malo.json"), 'portada');
        T::eq($ok, $store->currentId());
        T::ok(is_file("$d/pub/current/index.html"));
        T::eq([], array_filter(scandir("$d/pub/releases") ?: [], fn($e) => str_starts_with($e, '.building')), 'sin temporales');
    });
}

T::test('publicación: la retención conserva las N más recientes y la activa', function () use ($ctx) {
    [, , $store, $pub, $content] = $ctx('symlink', 2);
    $ids = [];
    for ($i = 0; $i < 4; $i++) {
        $ids[] = $pub->publish($content, true)['id'];
    }
    $left = $store->all();
    T::eq(2, count($left));
    T::eq(array_slice($ids, 2), $left);
});
T::test('publicación: dos publicaciones simultáneas, la segunda se rechaza', function () use ($ctx) {
    [$d, , $store, $pub, $content] = $ctx('symlink');
    $store->init();
    $lock = fopen("$d/pub/.publish.lock", 'c');
    flock($lock, LOCK_EX);
    T::throws(LuxException::class, fn() => $pub->publish($content, true), 'en curso');
    flock($lock, LOCK_UN);
    T::ok($pub->publish($content, true)['id'] !== '');
});
T::test('activación [rename]: recuperación tras caída entre los dos renombrados', function () use ($ctx) {
    [$d, , $store, $pub, $content] = $ctx('rename');
    $a = $pub->publish($content, true)['id'];
    $b = $pub->publish($content, true)['id'];
    // Simula la caída: la activa (b) se retiró a releases/ y todavía no se movió la nueva (a).
    rename("$d/pub/current", "$d/pub/releases/$b");
    file_put_contents("$d/pub/.activating.json", json_encode(['from' => $b, 'to' => $a]));
    T::ok(!is_dir("$d/pub/current"));
    T::ok((new Activator($store, 'rename'))->recover());
    T::eq($b, $store->currentId(), 'se restaura la versión que se estaba sirviendo');
    T::ok(is_file("$d/pub/current/index.html"));
});
T::test('activación: id inválido o versión inexistente se rechazan', function () use ($ctx) {
    [, , $store] = $ctx('symlink');
    $store->init();
    $act = new Activator($store, 'symlink');
    T::throws(LuxException::class, fn() => $act->activate('../../etc'), 'no válido');
    T::throws(LuxException::class, fn() => $act->activate('20200101000000000000-abcd'), 'no existe');
});
T::test('preflight: detecta enlaces rotos, archivos privados y faltantes', function () {
    $d = T::tmpDir();
    file_put_contents("$d/index.html", '<a href="/no-existe/">x</a><img src="/img/a.webp">');
    file_put_contents("$d/datos.sqlite", 'x');
    file_put_contents("$d/shell.php", '<?php');
    symlink('/etc/passwd', "$d/pw");
    $e = implode("\n", (new Preflight())->check($d));
    foreach (['404.html', 'Enlace interno roto /no-existe/', 'Enlace interno roto /img/a.webp', 'datos.sqlite', 'shell.php', 'Enlace simbólico'] as $needle) {
        T::ok(str_contains($e, $needle), "debe informar: $needle");
    }
});
T::test('borrado seguro: no sale del área ni sigue enlaces', function () {
    $d = T::tmpDir();
    mkdir("$d/in/sub", 0755, true);
    mkdir("$d/fuera", 0755);
    file_put_contents("$d/fuera/a.txt", 'a');
    symlink("$d/fuera", "$d/in/sub/lnk");
    LuxAeterna\Core\Fs::removeTree("$d/in/sub", "$d/in");
    T::ok(is_file("$d/fuera/a.txt"), 'el destino del enlace sigue intacto');
    T::throws(LuxException::class, fn() => LuxAeterna\Core\Fs::removeTree("$d/fuera", "$d/in"), 'fuera del área');
});

T::test('publicación: contenido idéntico se omite salvo force', function () use ($ctx) {
    [$d, , $store, $pub, $content] = $ctx('symlink');
    $a = $pub->publish($content);
    T::ok(!$a['skipped']);
    $b = $pub->publish($content);
    T::ok($b['skipped']);
    T::eq($a['id'], $b['id']);
    T::eq(1, count($store->stored()));
    $c = $pub->publish($content, true);
    T::ok(!$c['skipped'] && $c['id'] !== $a['id']);
    T::ok(!glob("$d/pub/releases/.building-*"), 'sin restos de construcción');
});
T::test('publicación: restos .building-* de una caída se limpian', function () use ($ctx) {
    [$d, , $store, $pub, $content] = $ctx('symlink');
    $pub->publish($content);
    mkdir("$d/pub/releases/.building-20200101000000000000-aaaa/x", 0755, true);
    $pub->publish($content, true);
    T::ok(!glob("$d/pub/releases/.building-*"));
});
T::test('publicación: recursos con hash de versiones previas se conservan y constan en los metadatos', function () use ($ctx) {
    [$d, , $store, $pub, $content] = $ctx('symlink');
    $a = $pub->publish($content)['id'];
    $oldCss = ($store->meta($a)['assets_own'] ?? [])[0];
    file_put_contents("$d/pub/releases/$a/assets/viejo.abc123.css", 'x');
    $m = json_decode(file_get_contents("$d/pub/releases/$a/.lux-release.json"), true);
    $m['assets_own'][] = '/assets/viejo.abc123.css';
    file_put_contents("$d/pub/releases/$a/.lux-release.json", json_encode($m));
    $b = $pub->publish($content, true)['id'];
    T::ok(is_file("$d/pub/releases/$b/assets/viejo.abc123.css"), 'recurso antiguo arrastrado');
    T::ok(is_file("$d/pub/releases/$b$oldCss"));
    T::ok(in_array('/assets/viejo.abc123.css', $store->meta($b)['assets'], true));
    T::ok(!in_array('/assets/viejo.abc123.css', $store->meta($b)['assets_own'], true));
});
T::test('publicación: la guarda de espacio impide publicar y deja el sitio activo', function () use ($ctx) {
    [$d, $c, $store, , $content] = $ctx('symlink');
    $cfg = Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://luxaeterna.es'], 'paths' => ['data' => "$d/var", 'publish' => "$d/pub"], 'publishing' => ['activation' => 'symlink', 'min_free_mb' => 999999999]], dirname(__DIR__));
    $ok = (new Publisher($c, $store, new Logger("$d/var/logs"), dirname(__DIR__) . '/components/layouts/base'))->publish($content);
    $bad = new Publisher($cfg, $store, new Logger("$d/var/logs"), dirname(__DIR__) . '/components/layouts/base');
    T::throws(LuxException::class, fn() => $bad->publish($content, true), 'Espacio');
    T::eq($ok['id'], $store->currentId());
});
T::test('preflight: avisos editoriales no bloquean y noindex accidental sí', function () {
    $d = T::tmpDir();
    $page = fn(string $t, string $x = '') => "<!doctype html><title>$t</title><link rel=\"canonical\" href=\"https://x.es/\">$x";
    file_put_contents("$d/index.html", $page('Igual'));
    file_put_contents("$d/404.html", $page('No'));
    mkdir("$d/a");
    file_put_contents("$d/a/index.html", $page('Igual'));
    $r = (new Preflight())->run($d);
    T::eq([], $r['errors']);
    T::contains('Título repetido', implode("\n", $r['warnings']));
    T::contains('Sin descripción', implode("\n", $r['warnings']));
    file_put_contents("$d/a/index.html", $page('Otro', '<meta name="robots" content="noindex">'));
    T::contains('noindex accidental', implode("\n", (new Preflight())->check($d)));
});
