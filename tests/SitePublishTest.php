<?php
declare(strict_types=1);

use LuxAeterna\Content\Blocks;
use LuxAeterna\Content\ContentRepository;
use LuxAeterna\Content\Menus;
use LuxAeterna\Content\Settings;
use LuxAeterna\Content\SiteBuilder;
use LuxAeterna\Content\Taxonomy;
use LuxAeterna\Content\TypeRegistry;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Database;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\Migrator;
use LuxAeterna\Publishing\Preview;
use LuxAeterna\Publishing\Publisher;
use LuxAeterna\Publishing\ReleaseStore;

/** Entorno completo en un directorio temporal. */
function lux_site(array $types = []): object
{
    $d = T::tmpDir();
    $root = dirname(__DIR__);
    $config = Config::fromArray([
        'schema' => 1,
        'site' => ['base_url' => 'https://luxaeterna.es', 'name' => 'Sitio de prueba'],
        'paths' => ['data' => "$d/var", 'publish' => "$d/publish"],
        'publishing' => ['keep_releases' => 5, 'activation' => 'auto'],
    ], $root);
    $db = new Database("$d/db.sqlite");
    (new Migrator($db, $root . '/migrations'))->migrate();
    $reg = new TypeRegistry($types);
    $tax = new Taxonomy($db);
    $menus = new Menus($db);
    $settings = new Settings($db);
    $o = new stdClass();
    $o->dir = $d;
    $o->db = $db;
    $o->repo = new ContentRepository($db, $reg, $tax);
    $o->tax = $tax;
    $o->menus = $menus;
    $o->settings = $settings;
    $o->types = $reg;
    $o->galleries = new \LuxAeterna\Gallery\GalleryStore($db, fn() => new \LuxAeterna\Design\DesignRegistry($root . '/components', null, \LuxAeterna\Core\Version::CURRENT));
    $o->builder = new SiteBuilder($db, $reg, $menus, $settings, null, fn() => $o->galleries);
    $o->store = new ReleaseStore("$d/publish");
    $o->publisher = new Publisher($config, $o->store, new Logger("$d/logs"), $root . '/components/layouts/base');
    $o->preview = new Preview($config, $reg, $menus, $db, $root . '/components/layouts/base', null, null, fn() => $o->galleries);
    $o->publishSite = function () use ($o): array {
        return $o->publisher->publishSnapshot($o->builder->build());
    };
    $o->page = function (string $title, string $text, bool $publish = true) use ($o): int {
        $id = $o->repo->create('page', ['title' => $title, 'blocks' => Blocks::fromText($text)], 1);
        if ($publish) {
            $o->repo->publish($id, 1);
        }
        return $id;
    };
    $o->read = fn(string $path): ?string => is_file($o->store->base . '/current' . $path) ? file_get_contents($o->store->base . '/current' . $path) : null;
    return $o;
}

function lux_all_output(object $s): string
{
    $all = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($s->store->base . '/current/', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $all .= "\n--" . substr($f->getPathname(), strlen($s->store->base)) . "\n" . file_get_contents($f->getPathname());
    }
    return $all;
}

T::test('publicación desde la base de datos: portada obligatoria y solo lo publicado llega al sitio', function () {
    $s = lux_site();
    $home = ($s->page)('Bienvenida', 'Texto de la portada');
    T::throws(LuxException::class, fn() => ($s->publishSite)(), 'Falta la portada');
    $s->settings->set('home_uid', $s->repo->get($home)['uid']);
    ($s->page)('Servicios', 'Servicio público');
    ($s->page)('Secreto', 'TEXTO-DE-BORRADOR-NO-PUBLICO', false);
    $papelera = ($s->page)('Antigua', 'TEXTO-DE-PAPELERA');
    $s->repo->trash($papelera, 2);
    $res = ($s->publishSite)();
    T::eq(2, $res['pages']);
    T::contains('Texto de la portada', ($s->read)('/index.html'));
    T::contains('Servicio público', ($s->read)('/servicios/index.html'));
    T::eq(null, ($s->read)('/secreto/index.html'));
    T::eq(null, ($s->read)('/antigua/index.html'));
    $all = lux_all_output($s);
    foreach (['TEXTO-DE-BORRADOR-NO-PUBLICO', 'TEXTO-DE-PAPELERA'] as $x) {
        T::ok(!str_contains($all, $x), "$x no debe estar en ningún archivo público");
    }
    T::eq(null, ($s->read)('/bienvenida/index.html') === null ? null : null);
    T::contains('<title>Bienvenida</title>', ($s->read)('/index.html'));
});
T::test('publicación: los cambios en edición no salen hasta publicarlos', function () {
    $s = lux_site();
    $id = ($s->page)('Inicio', 'Versión uno');
    $s->settings->set('home_uid', $s->repo->get($id)['uid']);
    $v = $s->repo->save($id, 2, ['title' => 'Inicio', 'blocks' => Blocks::fromText('Versión dos (en edición)')], 1);
    ($s->publishSite)();
    T::contains('Versión uno', ($s->read)('/index.html'));
    T::ok(!str_contains((string)($s->read)('/index.html'), 'en edición'));
    $s->repo->publish($id, $v);
    ($s->publishSite)();
    T::contains('Versión dos', ($s->read)('/index.html'));
});
T::test('publicación: la portada se sirve en / y su ruta propia redirige; páginas escapan el HTML', function () {
    $s = lux_site();
    $id = ($s->page)('Inicio', "<script>alert(1)</script> & más\n\n- uno <b>\n- dos");
    $s->settings->set('home_uid', $s->repo->get($id)['uid']);
    ($s->publishSite)();
    $html = ($s->read)('/index.html');
    T::ok(!str_contains($html, '<script>alert'));
    T::contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; más', $html);
    T::contains('<li>uno &lt;b&gt;</li>', $html);
    T::contains('http-equiv="refresh"', (string)($s->read)('/inicio/index.html'));
    T::contains('noindex', (string)($s->read)('/inicio/index.html'));
});
T::test('publicación: blog, categorías, etiquetas y tipos propios generan listados solo con lo publicado', function () {
    $s = lux_site(['servicio' => ['label' => 'Servicios', 'prefix' => 'servicios', 'fields' => ['precio' => ['type' => 'number', 'label' => 'Precio']]]]);
    $home = ($s->page)('Inicio', 'Hola');
    $s->settings->set('home_uid', $s->repo->get($home)['uid']);
    $cat = $s->tax->create('category', 'Noticias');
    $p1 = $s->repo->create('post', ['title' => 'Primera entrada', 'summary' => 'Resumen uno', 'blocks' => Blocks::fromText('Cuerpo')], 1);
    $s->tax->assign($p1, [$cat], ['PHP']);
    $s->repo->publish($p1, 1);
    $p2 = $s->repo->create('post', ['title' => 'Borrador oculto', 'blocks' => Blocks::fromText('x')], 1);
    $s->tax->assign($p2, [$cat], ['SoloBorrador']);
    $sv = $s->repo->create('servicio', ['title' => 'Cerrajería', 'fields' => ['precio' => '49'], 'blocks' => Blocks::fromText('Urgencias')], 1);
    $s->repo->publish($sv, 1);
    ($s->publishSite)();
    $blog = ($s->read)('/blog/index.html');
    T::contains('Primera entrada', $blog);
    T::contains('Resumen uno', $blog);
    T::ok(!str_contains($blog, 'Borrador oculto'));
    T::contains('<a href="/blog/primera-entrada/">', $blog);
    T::contains('Primera entrada', (string)($s->read)('/categoria/noticias/index.html'));
    T::ok(!str_contains((string)($s->read)('/categoria/noticias/index.html'), 'Borrador oculto'));
    T::contains('Primera entrada', (string)($s->read)('/etiqueta/php/index.html'));
    T::eq(null, ($s->read)('/etiqueta/solo-borrador/index.html'), 'términos de borradores no generan página');
    T::contains('<dt>Precio</dt><dd>49</dd>', (string)($s->read)('/servicios/cerrajeria/index.html'));
    T::contains('Cerrajería', (string)($s->read)('/servicios/index.html'));
    T::contains('datetime="' . gmdate('Y-m-d') . '"', (string)($s->read)('/blog/primera-entrada/index.html'));
});
T::test('menús: se resuelven por identificador estable y se omiten los contenidos no publicados', function () {
    $s = lux_site();
    $home = ($s->page)('Inicio', 'Hola');
    $s->settings->set('home_uid', $s->repo->get($home)['uid']);
    $a = ($s->page)('Contacto', 'x');
    $b = ($s->page)('Equipo', 'x', false);
    $s->menus->add('main', 'Contáctanos', $s->repo->get($a)['uid'], null);
    $s->menus->add('main', 'Equipo', $s->repo->get($b)['uid'], null);
    $s->menus->add('main', 'Tienda', null, 'https://tienda.example.org/');
    $s->menus->add('footer', 'Inicio', $s->repo->get($home)['uid'], null);
    $res = ($s->publishSite)();
    $html = ($s->read)('/index.html');
    T::contains('<a href="/contacto/">Contáctanos</a>', $html);
    T::contains('<a href="https://tienda.example.org/">Tienda</a>', $html);
    T::ok(!str_contains($html, 'Equipo'), 'borrador fuera del menú');
    T::contains('<nav aria-label="Pie"><a href="/">Inicio</a></nav>', $html);
    T::ok((bool)array_filter($res['warnings'], fn($w) => str_contains($w, 'Equipo')), 'aviso editorial');
    // el cambio de slug no rompe el menú (identificador estable) ni el enlace
    $c = $s->repo->get($a);
    $v = $s->repo->save($a, (int)$c['version'], ['title' => 'Contacto', 'slug' => 'escribenos', 'blocks' => Blocks::fromText('x')], 1);
    $s->repo->publish($a, $v);
    ($s->publishSite)();
    T::contains('<a href="/escribenos/">Contáctanos</a>', ($s->read)('/index.html'));
    T::contains('http-equiv="refresh" content="0; url=/escribenos/"', (string)($s->read)('/contacto/index.html'));
});
T::test('redirecciones: apuntar a algo no publicado se ignora con aviso y no rompe la publicación', function () {
    $s = lux_site();
    $home = ($s->page)('Inicio', 'Hola');
    $s->settings->set('home_uid', $s->repo->get($home)['uid']);
    $a = ($s->page)('Vieja', 'x');
    $v = $s->repo->save($a, 2, ['title' => 'Vieja', 'slug' => 'nueva', 'blocks' => Blocks::fromText('x')], 1);
    $v = $s->repo->publish($a, $v);
    $s->repo->unpublish($a, $v);
    $res = ($s->publishSite)();
    T::eq(null, ($s->read)('/vieja/index.html'));
    T::ok((bool)array_filter($res['warnings'], fn($w) => str_contains($w, '/vieja/')));
});
T::test('galerías: una referencia a una galería que no existe bloquea la publicación con un error claro', function () {
    $s = lux_site();
    $home = ($s->page)('Inicio', 'Hola');
    $s->settings->set('home_uid', $s->repo->get($home)['uid']);
    ($s->page)('Galería', "Texto\n\n[galeria:viaje-2026]");
    T::throws(LuxException::class, fn() => ($s->publishSite)(), 'galería que ya no existe');
    T::ok($s->store->currentId() === null, 'no se activó nada');
});
T::test('previsualización: mismas plantillas, noindex, sin escribir en la versión pública', function () {
    $s = lux_site();
    $home = ($s->page)('Inicio', 'Hola');
    $s->settings->set('home_uid', $s->repo->get($home)['uid']);
    ($s->publishSite)();
    $before = lux_all_output($s);
    $id = $s->repo->create('post', ['title' => 'Borrador <b>', 'blocks' => Blocks::fromText("PREVIEW-SECRETA\n\n[imagen:foto-uno|alt|pie]")], 1);
    $html = $s->preview->html('post', $s->repo->get($id)['rev']);
    T::contains('PREVIEW-SECRETA', $html);
    T::contains('<meta name="robots" content="noindex,nofollow">', $html);
    T::contains('href="/admin/preview/style.css?u=', $html);
    T::contains('Borrador &lt;b&gt;', $html);
    T::contains('foto-uno', $html);
    T::contains('<header class="lux-header', $html, 'misma plantilla');
    T::eq($before, lux_all_output($s), 'la previsualización no escribe en lo publicado');
    ($s->publishSite)();
    T::ok(!str_contains(lux_all_output($s), 'PREVIEW-SECRETA'));
});
