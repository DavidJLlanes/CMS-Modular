<?php
declare(strict_types=1);

use CMSModular\Content\BlockValidator;
use CMSModular\Content\Blocks;
use CMSModular\Content\Inline;
use CMSModular\Content\Page;
use CMSModular\Core\LuxException;
use CMSModular\Publishing\BlockRenderer;

function lux_render(array $blocks): string
{
    $p = new Page('t', 't', 'T', '', $blocks, false);
    return BlockRenderer::render($p, null);
}

T::test('editor: formato en línea validado y escapado; HTML activo y enlaces peligrosos se rechazan', function () {
    $b = Blocks::validate([['type' => 'paragraph', 'inlines' => [
        ['t' => 'Hola <b>x</b> ', 'b' => true], ['t' => 'enlace', 'a' => 'https://ejemplo.es/a', 'i' => true, 'c' => '#AA0000'],
    ]]]);
    $h = lux_render($b);
    T::contains('<strong>Hola &lt;b&gt;x&lt;/b&gt; </strong>', $h);
    T::contains('rel="noopener noreferrer"', $h);
    T::contains('lux-c-aa0000', $h);
    T::ok(!str_contains($h, 'style='), 'sin estilos en línea');
    foreach (['javascript:alert(1)', 'data:text/html,x', ' javascript:x', '//evil.es', "/a\" onclick=\"x"] as $bad) {
        T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'paragraph', 'inlines' => [['t' => 'x', 'a' => $bad]]]]));
    }
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'paragraph', 'inlines' => [['t' => 'x', 'c' => 'red;background:url(x)']]]]));
    $b = Blocks::validate([['type' => 'paragraph', 'onclick' => 'x', 'style' => ['size' => 'lg', 'bogus' => 'y', 'color' => 'primary'], 'align' => 'center', 'inlines' => [['t' => 'a', 'onmouseover' => 'x']]]]);
    T::eq('<p class="lux-a-center lux-t-size-lg lux-c-primary">a</p>' . "\n", lux_render($b));
});

T::test('editor: columnas, tablas, botones y separadores generan HTML semántico', function () {
    $b = Blocks::validate([
        ['type' => 'columns', 'layout' => '1-2', 'gap' => 'l', 'cols' => [[['type' => 'paragraph', 'text' => 'A']], [['type' => 'heading', 'level' => 3, 'text' => 'B'], ['type' => 'button', 'label' => 'Ir', 'href' => '/contacto/']]]],
        ['type' => 'table', 'caption' => 'Precios', 'header_row' => true, 'aligns' => ['left', 'right'], 'rows' => [['Plan', 'Precio'], ['Base', ['inlines' => [['t' => '5', 'b' => true]]]]]],
        ['type' => 'separator', 'style' => 'dots'],
    ]);
    $h = lux_render($b);
    T::contains('<div class="lux-cols lux-cols--1-2 lux-cols--gap-l">', $h);
    T::ok(strpos($h, '>A<') < strpos($h, '>B<'), 'orden de lectura = orden del DOM');
    T::contains('<th scope="col">Plan</th>', $h);
    T::contains('<caption>Precios</caption>', $h);
    T::contains('role="region" tabindex="0"', $h);
    T::contains('<td class="lux-a-right"><strong>5</strong></td>', $h);
    T::contains('<hr class="lux-sep lux-sep--dots">', $h);
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'columns', 'layout' => '1-1', 'cols' => [[['type' => 'columns', 'layout' => '1-1', 'cols' => [[], []]]], []]]]), 'columnas');
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'table', 'rows' => [['a', 'b'], ['c']]]]), 'mismo número');
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'button', 'label' => 'x', 'href' => 'javascript:x']]));
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'html', 'html' => '<script>']]));
});

T::test('editor: imágenes y colores dentro de columnas se detectan; sin formato vuelve a la forma sencilla', function () {
    $b = Blocks::validate([['type' => 'columns', 'layout' => '1-1', 'cols' => [[['type' => 'image', 'ref' => 'img-abc123456789', 'alt' => 'x']], [['type' => 'paragraph', 'inlines' => [['t' => 'a', 'c' => '#112233']]]]]]]);
    T::eq(['img-abc123456789'], Blocks::mediaRefs($b));
    T::eq(['#112233'], Blocks::colors($b));
    $p = Blocks::validate([['type' => 'paragraph', 'inlines' => [['t' => 'a'], ['t' => 'b']]]]);
    T::eq([['type' => 'paragraph', 'text' => 'ab']], $p);
    T::ok(Blocks::isPlain($p));
    T::ok(!Blocks::isPlain($b));
    T::eq($p, Blocks::fromText(Blocks::toText($p)));
});

T::test('editor: H1 permitido con aviso; extensiones solo si están registradas', function () {
    $w = CMSModular\Content\SiteBuilder::editorialWarnings('Inicio', Blocks::validate([['type' => 'heading', 'level' => 1, 'text' => 'X'], ['type' => 'heading', 'level' => 4, 'text' => 'Y']]));
    T::eq(2, count($w));
    T::contains('nivel 1', $w[0]);
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'extension', 'kind' => 'mapa', 'data' => []]]), 'no está instalada');
    BlockValidator::registerExtension('mapa', fn(array $d): array => ['q' => substr((string)($d['q'] ?? ''), 0, 50)], fn(array $d): string => '<div class="mapa">' . BlockRenderer::e($d['q']) . "</div>\n");
    $b = Blocks::validate([['type' => 'extension', 'kind' => 'mapa', 'data' => ['q' => '<x>', 'evil' => 1]]]);
    T::eq('<div class="mapa">&lt;x&gt;</div>' . "\n", lux_render($b));
    BlockValidator::resetExtensions();
});
