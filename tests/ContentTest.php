<?php
declare(strict_types=1);

use CMSModular\Content\ContentLoader;
use CMSModular\Core\LuxException;
use CMSModular\Publishing\Template;

$row = fn(string $id, string $slug, string $title = 'T') => ['id' => $id, 'slug' => $slug, 'title' => $title, 'body' => []];

T::test('contenido: carga el sitio de ejemplo', function () {
    $p = (new ContentLoader())->load(dirname(__DIR__) . '/examples/sitio-demo/content.json');
    T::eq(2, count($p));
    T::eq('/', $p[0]->path());
    T::eq('/sobre-nosotros/', $p[1]->path());
});
T::test('contenido: slugs inválidos, reservados y repetidos', function () use ($row) {
    $l = new ContentLoader();
    foreach (['../etc', 'A', 'a/b', 'a b', 'a--', '-a', 'admin', 'assets'] as $bad) {
        T::throws(LuxException::class, fn() => $l->pages([$row('i', ''), $row('x', $bad)]), '');
    }
    T::throws(LuxException::class, fn() => $l->pages([$row('i', ''), $row('a', 'x'), $row('b', 'x')]), 'Colisión');
    T::throws(LuxException::class, fn() => $l->pages([$row('a', 'x')]), 'portada');
});
T::test('contenido: bloques no soportados se rechazan', function () use ($row) {
    $r = $row('i', '');
    $r['body'] = [['type' => 'html', 'text' => '<script>']];
    T::throws(LuxException::class, fn() => (new ContentLoader())->pages([$r]), 'Bloque');
});
T::test('plantilla: escapa por defecto y solo inserta HTML con {{{ }}}', function () {
    T::eq('&lt;b&gt; &amp; &quot;x&quot;', Template::render('{{ a }}', ['a' => '<b> & "x"']));
    T::eq('<b>', Template::render('{{{ a }}}', ['a' => '<b>']));
    T::throws(LuxException::class, fn() => Template::render('{{ nope }}', []), 'desconocido');
});
