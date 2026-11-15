<?php
declare(strict_types=1);

use LuxAeterna\Content\ContentLoader;
use LuxAeterna\Content\Page;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Logger;
use LuxAeterna\Design\Contrast;
use LuxAeterna\Design\DesignRegistry;
use LuxAeterna\Design\DesignSettings;
use LuxAeterna\Design\FieldSchema;
use LuxAeterna\Design\FontCatalog;
use LuxAeterna\Design\Logo;
use LuxAeterna\Design\Theme;
use LuxAeterna\Publishing\Preflight;
use LuxAeterna\Publishing\Publisher;
use LuxAeterna\Publishing\ReleaseStore;

/** Copia del directorio components/ en un temporal para añadir o romper diseños sin tocar el repositorio. */
function lux_components(): string
{
    $d = T::tmpDir() . '/components';
    mkdir($d, 0755, true);
    exec('cp -a ' . escapeshellarg(dirname(__DIR__) . '/components') . '/. ' . escapeshellarg($d));
    return $d;
}

/** Duplica un diseño con otro id y aplica $mut al manifiesto. @param callable(array):array $mut */
function lux_clone_design(string $comp, string $from, string $to, ?callable $mut = null): string
{
    $src = glob("$comp/*/$from")[0];
    $dst = dirname($src) . "/$to";
    exec('cp -a ' . escapeshellarg($src) . ' ' . escapeshellarg($dst));
    $m = json_decode((string)file_get_contents("$dst/manifest.json"), true);
    $m['id'] = $to;
    if ($mut !== null) {
        $m = $mut($m);
    }
    file_put_contents("$dst/manifest.json", json_encode($m, JSON_PRETTY_PRINT));
    return $dst;
}

function lux_registry(string $comp): DesignRegistry
{
    return new DesignRegistry($comp, dirname($comp) . '/designs.json', '0.4.0');
}

T::test('diseños: se detectan los de ejemplo y todos son válidos', function () {
    $i = lux_registry(lux_components())->index();
    T::eq(['barra-aviso', 'cabecera-basica', 'cabecera-corporativa', 'footer-basico', 'footer-centrado', 'hero-simple'], array_keys($i['designs']));
    T::eq([], $i['errors']);
    foreach ($i['designs'] as $d) {
        T::ok(is_file(dirname(__DIR__) . '/components/' . $d['dir'] . '/preview.webp'), 'vista previa WebP de ' . $d['id']);
    }
});
T::test('diseños: copiar una carpeta con contrato válido la hace aparecer sin tocar código y se invalida la caché', function () {
    $comp = lux_components();
    $reg = lux_registry($comp);
    T::ok($reg->get('cabecera-corporativa3') === null);
    lux_clone_design($comp, 'cabecera-corporativa', 'cabecera-corporativa3');
    T::ok(lux_registry($comp)->get('cabecera-corporativa3') !== null, 'nueva instancia: caché invalidada por huella');
    T::ok($reg->refresh()['designs']['cabecera-corporativa3'] !== null, 'refresh explícito');
    file_put_contents("$comp/cabeceras/cabecera-corporativa3/style.css", ".lux-corp{color:red}\n");
    T::ok(lux_registry($comp)->get('cabecera-corporativa3')['hash'] !== $reg->get('cabecera-corporativa')['hash'], 'cambio de archivo cambia la huella');
});
T::test('diseños: el contrato rechaza manifiestos, rutas, enlaces y contenido peligrosos', function () {
    $comp = lux_components();
    $bad = [
        'id-malo' => fn($m) => ['id' => 'Mal Id'] + $m,
        'tipo-malo' => fn($m) => ['type' => 'sidebar'] + $m,
        'sin-plantilla' => fn($m) => ['template' => 'nada.html'] + $m,
        'ruta-externa' => fn($m) => ['template' => '../cabecera-basica/template.html'] + $m,
        'ruta-abs' => fn($m) => ['css' => '/etc/passwd'] + $m,
        'version-mala' => fn($m) => ['version' => 'uno'] + $m,
        'futuro' => fn($m) => ['requires' => ['lux' => '9.0.0']] + $m,
        'campo-reservado' => fn($m) => ['fields' => ['nav' => ['type' => 'text', 'label' => 'x', 'default' => '']]] + $m,
        'campo-tipo' => fn($m) => ['fields' => ['x' => ['type' => 'html', 'label' => 'x', 'default' => '']]] + $m,
        'recurso-remoto' => fn($m) => ['resources' => ['fonts' => ['https://evil.example/f.woff2']]] + $m,
        'schema-2' => fn($m) => ['schema' => 2] + $m,
    ];
    foreach ($bad as $id => $mut) {
        lux_clone_design($comp, 'cabecera-basica', $id, $mut);
    }
    $reg = lux_registry($comp)->refresh();
    foreach (array_keys($bad) as $id) {
        T::ok(!isset($reg['designs'][$id]) && !isset($reg['designs']['Mal Id']), "rechazado: $id");
    }
    T::ok(count($reg['errors']) >= count($bad), 'cada rechazo explica el motivo');
    T::eq(6, count($reg['designs']), 'los diseños buenos siguen funcionando');
});
T::test('diseños: plantillas, CSS y JS con capacidades peligrosas se rechazan', function () {
    $comp = lux_components();
    $cases = [
        'tpl-script' => ['template.html', '<header><script>alert(1)</script>{{{ logo }}}</header>'],
        'tpl-onclick' => ['template.html', '<header onclick="x()">{{{ logo }}}</header>'],
        'tpl-style' => ['template.html', '<header style="color:red">{{{ logo }}}</header>'],
        'tpl-remoto' => ['template.html', '<header><img src="https://evil.example/a.png"></header>'],
        'tpl-marcador' => ['template.html', '<header>{{ no_existe }}</header>'],
        'tpl-raw-campo' => ['template.html', '<header>{{{ posicion }}}</header>'],
        'tpl-esc-raw' => ['template.html', '<header>{{ nav }}</header>'],
        'css-import' => ['style.css', "@import url(x.css);\n"],
        'css-url' => ['style.css', ".a{background:url(//evil.example/x.png)}\n"],
        'css-expr' => ['style.css', ".a{width:expression(alert(1))}\n"],
    ];
    foreach ($cases as $id => [$file, $content]) {
        $dst = lux_clone_design($comp, 'cabecera-basica', $id);
        file_put_contents("$dst/$file", $content);
    }
    $dst = lux_clone_design($comp, 'cabecera-basica', 'js-fetch', fn($m) => ['js' => 'script.js'] + $m);
    file_put_contents("$dst/script.js", "fetch('/x');\n");
    $dst = lux_clone_design($comp, 'cabecera-basica', 'js-ok', fn($m) => ['js' => 'script.js'] + $m);
    file_put_contents("$dst/script.js", "document.documentElement.classList.add('js');\n");
    $reg = lux_registry($comp)->refresh();
    foreach (array_keys($cases) as $id) {
        T::ok(!isset($reg['designs'][$id]), "rechazado: $id");
    }
    T::ok(!isset($reg['designs']['js-fetch']) && isset($reg['designs']['js-ok']));
    $hero = lux_clone_design($comp, 'hero-simple', 'hero-sin-h1');
    file_put_contents("$hero/template.html", '<section>{{ title }}</section>');
    T::ok(!isset(lux_registry($comp)->refresh()['designs']['hero-sin-h1']), 'un hero debe incluir el h1');
});
T::test('diseños: enlaces simbólicos, carpetas duplicadas y escapes de directorio', function () {
    $comp = lux_components();
    $a = lux_clone_design($comp, 'cabecera-basica', 'con-enlace');
    symlink('/etc/passwd', "$a/secreto.txt");
    $b = lux_clone_design($comp, 'cabecera-basica', 'dup');
    $c = dirname($b) . '/dup-copia';
    exec('cp -a ' . escapeshellarg($b) . ' ' . escapeshellarg($c));
    mkdir("$comp/fuera-de-aqui", 0755);
    symlink($b, "$comp/cabeceras/enlace-a-carpeta");
    $reg = lux_registry($comp)->refresh();
    T::ok(!isset($reg['designs']['con-enlace']), 'enlace simbólico dentro del diseño');
    T::ok(!isset($reg['designs']['dup']), 'id duplicado: se ignoran ambos');
    T::contains('duplicado', json_encode($reg['errors']));
    T::contains('simbólico', json_encode($reg['errors'], JSON_UNESCAPED_UNICODE));
});
T::test('campos: coerción y validación estricta', function () {
    $d = ['type' => 'select', 'label' => 'x', 'options' => ['a', 'b'], 'default' => 'a'];
    T::eq('b', FieldSchema::coerce($d, 'b'));
    T::eq(null, FieldSchema::coerce($d, 'c'));
    T::eq('#aabbcc', FieldSchema::coerce(['type' => 'color'], '#AABBCC'));
    T::eq(null, FieldSchema::coerce(['type' => 'color'], 'red'));
    T::eq(null, FieldSchema::coerce(['type' => 'text', 'max' => 3], 'abcd'));
    T::eq(null, FieldSchema::coerce(['type' => 'text'], "a\nb"));
    T::eq(5, FieldSchema::coerce(['type' => 'number', 'min' => 1, 'max' => 9], '5'));
    T::eq(null, FieldSchema::coerce(['type' => 'number', 'min' => 1, 'max' => 9], '10'));
    T::throws(InvalidArgumentException::class, fn() => FieldSchema::validate(['a' => $d], ['zzz' => 'x']), 'desconocido');
});
T::test('ajustes: precedencia global → tipo → página → instancia, apagado y reinicio al cambiar de diseño', function () {
    $reg = lux_registry(lux_components());
    $s = new DesignSettings([
        'global' => ['header' => ['id' => 'cabecera-corporativa', 'values' => ['eslogan' => 'Global']], 'hero' => ['id' => 'hero-simple', 'values' => ['altura' => 'alta']]],
        'types' => ['post' => ['header' => ['values' => ['eslogan' => 'Tipo']], 'hero' => ['off' => true]]],
        'pages' => ['c_pagina1' => ['header' => ['values' => ['eslogan' => 'Página']], 'footer' => ['id' => 'footer-centrado']]],
        'instances' => ['c_pagina1' => ['header' => ['values' => ['fondo' => '#112233']]]],
    ], $reg);
    $h = $s->resolve('header', 'page', 'c_otra');
    T::eq(['cabecera-corporativa', 'Global'], [$h['id'], $h['values']['eslogan']]);
    T::eq('Tipo', $s->resolve('header', 'post', 'c_x')['values']['eslogan']);
    $p = $s->resolve('header', 'post', 'c_pagina1');
    T::eq(['Página', '#112233'], [$p['values']['eslogan'], $p['values']['fondo']]);
    T::eq(null, $s->resolve('hero', 'post', 'c_x'), 'apagado por tipo');
    T::eq('alta', $s->resolve('hero', 'page', 'c_x')['values']['altura']);
    T::eq('footer-centrado', $s->resolve('footer', 'page', 'c_pagina1')['id']);
    T::eq('footer-basico', $s->resolve('footer', 'page', 'c_otra')['id'], 'por defecto');
    T::eq(null, $s->resolve('topbar', 'page', 'c_x'), 'barra superior apagada por defecto');
    // un valor inválido en datos antiguos no rompe: se descarta
    $bad = new DesignSettings(['global' => ['footer' => ['id' => 'footer-basico', 'values' => ['texto' => str_repeat('x', 999)]]]], $reg);
    T::eq('', $bad->resolve('footer', 'page', 'c_x')['values']['texto']);
    // diseño retirado o de otra familia: la ranura se omite y se informa
    $gone = new DesignSettings(['global' => ['header' => ['id' => 'ya-no-existe'], 'footer' => ['id' => 'hero-simple']]], $reg);
    T::eq(null, $gone->resolve('header', 'page', 'c_x'));
    T::eq(2, count($gone->problems()));
});
T::test('ajustes: la validación del panel rechaza ranuras, diseños y valores no admitidos', function () {
    $comp = lux_components();
    $reg = lux_registry($comp);
    $f = new FontCatalog("$comp/fonts");
    $ok = DesignSettings::validate(['global' => ['header' => ['id' => 'cabecera-corporativa', 'values' => ['eslogan' => 'Hola', 'fondo' => '#ABCDEF']]], 'types' => ['post' => ['hero' => ['id' => 'hero-simple']]]], $reg, $f);
    T::eq('#abcdef', $ok['global']['header']['values']['fondo']);
    foreach ([
        ['global' => ['pie' => ['id' => 'footer-basico']]],
        ['global' => ['header' => ['id' => 'footer-basico']]],
        ['global' => ['header' => ['id' => 'cabecera-corporativa', 'values' => ['fondo' => 'rojo']]]],
        ['global' => ['header' => ['id' => 'cabecera-corporativa', 'values' => ['inventado' => 'x']]]],
        ['pages' => ['../x' => ['header' => ['off' => true]]]],
        ['theme' => ['colors' => ['bg' => 'blanco']]],
        ['theme' => ['typography' => ['body' => ['family' => 'no-existe']]]],
        ['theme' => ['typography' => ['body' => ['size' => 99]]]],
        ['logo' => ['mode' => 'image']],
        ['logo' => ['sizes' => ['header' => [100, 300]]]],
    ] as $i => $bad) {
        T::throws(InvalidArgumentException::class, fn() => DesignSettings::validate($bad, $reg, $f), '');
    }
});
T::test('tema: todas las paletas cumplen AA y la auditoría detecta combinaciones ilegibles', function () {
    foreach (Theme::PALETTES as $name => $c) {
        foreach (Contrast::audit($c) as $r) {
            T::ok($r['ok'], "paleta $name: {$r['pair']} {$r['ratio']}");
        }
    }
    T::eq(21.0, round(Contrast::ratio('#000000', '#ffffff'), 1));
    $c = Theme::PALETTES['claro'];
    $c['text'] = '#cccccc';
    T::ok(count(array_filter(Contrast::audit($c), fn($r) => !$r['ok'])) >= 2);
    $bad = array_filter(Contrast::audit(Theme::PALETTES['claro'], ['header' => ['bg' => '#ffff00', 'text' => '#ffffff']]), fn($r) => !$r['ok']);
    T::contains('Texto de cabecera', json_encode(array_values($bad), JSON_UNESCAPED_UNICODE));
});
T::test('tema: variables CSS, familias locales y límites', function () {
    $comp = lux_components();
    $f = new FontCatalog("$comp/fonts");
    $t = Theme::normalize(['colors' => ['primary' => '#0b5a80'], 'typography' => ['body' => ['family' => 'inter', 'size' => 18, 'line' => 1.7, 'align' => 'justify'], 'heading' => ['family' => 'lora', 'weight' => 700], 'spacing' => 'airy', 'width' => 52]], $f);
    $css = Theme::css($t, ['body' => $f->stack('inter'), 'heading' => $f->stack('lora')]);
    foreach (['--lux-primary:#0b5a80', '--lux-size:18px', '--lux-line:1.7', '--lux-width:52rem', 'text-align:justify', '"Lora",Georgia'] as $needle) {
        T::contains($needle, $css);
    }
    T::throws(InvalidArgumentException::class, fn() => Theme::normalize(['typography' => ['heading' => ['weight' => 500]]], $f), '700');
});
T::test('fuentes: catálogo local con licencia, cobertura de caracteres españoles y variantes usadas', function () {
    $f = new FontCatalog(lux_components() . '/fonts');
    T::eq(['inter', 'lora', 'source-serif-4'], array_keys($f->all()));
    foreach ($f->all() as $x) {
        T::eq('OFL-1.1', $x['license']);
    }
    T::eq([], $f->missingChars('inter', ['es', 'ca', 'gl', 'eu']));
    T::contains('ç', implode('', $f->missingChars('inter', ['es']) ?: ['ç']), 'sanity');
    $v = $f->used([['family' => 'inter', 'weights' => [400, 700], 'styles' => ['normal', 'italic']]]);
    T::eq(3, count($v), 'solo existen 400n, 400i y 700n; 700 cursiva usa la más cercana (400i)');
    T::contains('"Inter",system-ui', $f->stack('inter'));
    T::eq(FontCatalog::SYSTEM_STACK, $f->stack('system'));
});
T::test('fuentes: interpretación del CSS de Google solo acepta WOFF2 de gstatic y el subconjunto latino', function () {
    $css = "/* latin-ext */\n@font-face{font-family:'X';font-style:normal;font-weight:400;src:url(https://fonts.gstatic.com/s/x/a.woff2) format('woff2');unicode-range:U+0100-02AF;}\n"
        . "/* latin */\n@font-face{font-family:'X';font-style:normal;font-weight:400;src:url(https://fonts.gstatic.com/s/x/b.woff2) format('woff2');unicode-range:U+0000-00FF;}\n"
        . "/* latin */\n@font-face{font-family:'X';font-style:normal;font-weight:700;src:url(https://evil.example/c.woff2) format('woff2');unicode-range:U+0000-00FF;}\n";
    $r = FontCatalog::parseGoogleCss($css);
    T::eq(1, count($r));
    T::eq('https://fonts.gstatic.com/s/x/b.woff2', $r[0]['url']);
});
T::test('logo: proporción preservada, límites por dispositivo y SVG con scripts rechazado', function () {
    $d = T::tmpDir();
    $im = imagecreatetruecolor(400, 100);
    imagepng($im, "$d/logo.png");
    file_put_contents("$d/ok.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 50"><rect width="200" height="50"/></svg>');
    file_put_contents("$d/mal.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script></svg>');
    file_put_contents("$d/falso.png", 'no soy una imagen');
    T::eq(['logo.png', 'ok.svg'], Logo::available($d));
    $i = Logo::inspect($d, 'logo.png');
    T::eq([400, 100], [$i['w'], $i['h']]);
    $l = Logo::normalize(['mode' => 'image', 'image' => 'logo.png', 'sizes' => ['header' => [200, 100]]], $d);
    T::eq([200, 100], $l['sizes']['header']);
    foreach ([['sizes' => ['header' => [10, 10]]], ['sizes' => ['header' => [100, 200]]], ['sizes' => ['nada' => [100, 100]]], ['image' => '../logo.png'], ['image' => 'mal.svg']] as $bad) {
        T::throws(InvalidArgumentException::class, fn() => Logo::normalize($bad, $d), '');
    }
});

/** Publica un snapshot con ajustes de diseño sobre una copia temporal de components/. @return array{0:string,1:string,2:array} [dir, componentes, resultado] */
function lux_publish_design(array $design, ?callable $prepare = null): array
{
    $comp = lux_components();
    $d = dirname($comp);
    if ($prepare !== null) {
        $prepare($comp);
    }
    $cfg = Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://luxaeterna.es', 'name' => 'Lux'], 'paths' => ['data' => "$d/var", 'publish' => "$d/pub"], 'publishing' => ['activation' => 'symlink', 'min_free_mb' => 0]], dirname(__DIR__));
    $store = new ReleaseStore("$d/pub");
    $pages = (new ContentLoader())->load(dirname(__DIR__) . '/examples/sitio-demo/content.json');
    $pub = new Publisher($cfg, $store, new Logger("$d/var/logs"), "$comp/layouts/base");
    $res = $pub->publishSnapshot(new SiteSnapshot($pages, ['main' => [['label' => 'Sobre', 'url' => '/sobre-nosotros/']]], [], [], $design));
    return [$d, $comp, $res];
}

T::test('publicación con diseños: ranuras, hero, instancias y recursos con huella por página', function () {
    [$d, , $res] = lux_publish_design([
        'global' => ['header' => ['id' => 'cabecera-corporativa', 'values' => ['eslogan' => 'Tu <web>', 'fondo' => '#e9dfc9']], 'topbar' => ['id' => 'barra-aviso', 'values' => ['texto' => 'Oferta']], 'footer' => ['id' => 'footer-centrado']],
        'pages' => ['sobre-nosotros' => ['hero' => ['id' => 'hero-simple', 'values' => ['subtitulo' => 'Quiénes somos']]]],
    ]);
    $cur = "$d/pub/current";
    $home = file_get_contents("$cur/index.html");
    $about = file_get_contents("$cur/sobre-nosotros/index.html");
    T::contains('class="lux-corp"', $home);
    T::contains('Tu &lt;web&gt;', $home);
    T::contains('lux-topbar', $home);
    T::contains('lux-footer2', $home);
    T::eq(1, substr_count($home, '<h1'), 'un único h1 sin hero');
    T::eq(1, substr_count($about, '<h1'), 'con hero, el h1 lo pone el hero');
    T::contains('Quiénes somos', $about);
    T::ok(!str_contains($home, 'lux-hero'));
    preg_match('/lux-i-[0-9a-f]{8}/', $home, $m);
    T::ok($m !== [], 'clase de instancia para el color del campo');
    $inv = json_decode(file_get_contents("$cur/.lux-inventory.json"), true);
    T::ok(in_array('hero-simple', $inv['pages']['/sobre-nosotros/']['components'], true));
    T::ok(!in_array('hero-simple', $inv['pages']['/']['components'], true), 'el hero solo interviene donde se usa');
    $heroCss = array_values(array_filter($inv['modules']['hero-simple']['resources'], fn($r) => str_ends_with($r, '.css')))[0];
    T::ok(!in_array($heroCss, $inv['pages']['/']['resources'], true));
    T::ok(in_array($heroCss, $inv['pages']['/sobre-nosotros/']['resources'], true));
    foreach ($inv['shared'] as $shared) {
        T::ok(is_file($cur . $shared));
    }
    T::ok(count($inv['shared']) >= 3, 'core, cabecera y pie se comparten y no se duplican');
    T::eq([], array_filter(glob("$cur/assets/c/*.css") ?: [], fn($f) => !preg_match('/\.[0-9a-f]{10}\.css$/', $f)), 'todo con huella');
    $core = (string)file_get_contents($cur . glob_core($cur));
    T::contains('--lux-primary:', $core);
    T::contains('.lux-i-', $core);
    foreach (['index.html', 'sobre-nosotros/index.html', '404.html'] as $f) {
        T::eq([], array_filter((new Preflight())->check($cur), fn($e) => str_contains($e, 'remota')));
    }
    T::ok(is_file("$cur/404.html") && str_contains(file_get_contents("$cur/404.html"), 'lux-corp'));
});
function glob_core(string $cur): string
{
    return '/assets/' . basename(glob("$cur/assets/core.*.css")[0]);
}
T::test('publicación con diseños: solo se publican las fuentes realmente usadas y desde el propio dominio', function () {
    [$d] = lux_publish_design(['theme' => ['typography' => ['body' => ['family' => 'inter', 'size' => 18, 'line' => 1.6, 'align' => 'left'], 'heading' => ['family' => 'lora', 'weight' => 700]]]]);
    $cur = "$d/pub/current";
    $fonts = array_map('basename', glob("$cur/assets/fonts/*.woff2"));
    T::eq(4, count($fonts), 'inter 400n, 400i, 700n + lora 700n');
    T::eq(0, count(array_filter($fonts, fn($f) => str_starts_with($f, 'source-serif'))), 'una fuente del catálogo sin usar no se publica');
    $home = file_get_contents("$cur/index.html");
    T::contains('rel="preload"', $home);
    T::contains('as="font"', $home);
    $core = (string)file_get_contents($cur . glob_core($cur));
    T::contains('font-display:swap', $core);
    T::contains('unicode-range:U+0000-00FF', $core);
    T::ok(!preg_match('#https?://#', $core), 'CSS sin URL remotas');
    T::ok(!preg_match('#<(?:link|script)[^>]+(?:href|src)="https?://(?!luxaeterna)#', $home));
    T::eq([], array_filter((new Preflight())->check($cur), fn($e) => str_contains($e, 'remota')));
});
T::test('publicación con diseños: logo de imagen con proporción y tamaño por ubicación y dispositivo', function () {
    [$d] = lux_publish_design(['global' => ['footer' => ['id' => 'footer-centrado']], 'logo' => ['mode' => 'image', 'image' => 'marca.png', 'alt' => 'Mi marca', 'sizes' => ['header' => [200, 100], 'footer' => [100, 80]]]], function (string $comp) {
        mkdir("$comp/logos", 0755, true);
        $im = imagecreatetruecolor(400, 100);
        imagepng($im, "$comp/logos/marca.png");
    });
    $cur = "$d/pub/current";
    $home = file_get_contents("$cur/index.html");
    T::contains('width="200" height="50"', $home);
    T::contains('alt="Mi marca"', $home);
    T::contains('width="100" height="25"', $home);
    T::eq(1, count(glob("$cur/assets/logo.*.png")));
    $core = (string)file_get_contents($cur . glob_core($cur));
    T::contains('.lux-logo--header{width:200px;height:auto', $core);
    T::contains('@media (max-width:640px){.lux-logo--header{width:100px}}', $core);
});
T::test('publicación con diseños: un diseño nuevo copiado por carpeta se puede usar al instante', function () {
    [$d, , $res] = lux_publish_design(['global' => ['header' => ['id' => 'mi-cabecera']]], function (string $comp) {
        $dst = lux_clone_design($comp, 'cabecera-basica', 'mi-cabecera', fn($m) => ['name' => 'Mi cabecera'] + $m);
        file_put_contents("$dst/template.html", '<header class="mi-cab">{{{ logo }}}{{{ nav }}}</header>');
    });
    T::contains('class="mi-cab"', file_get_contents("$d/pub/current/index.html"));
    T::ok(is_file(glob("$d/pub/current/assets/c/mi-cabecera.*.css")[0]));
});
T::test('publicación con diseños: un diseño inválido o retirado no rompe el sitio (la ranura se omite y se avisa)', function () {
    [$d, , $res] = lux_publish_design(['global' => ['header' => ['id' => 'cabecera-retirada']]]);
    T::ok(!str_contains(file_get_contents("$d/pub/current/index.html"), '<header'));
    T::contains('cabecera-retirada', json_encode($res['warnings'], JSON_UNESCAPED_UNICODE));
    T::ok(is_file("$d/pub/current/index.html"));
});
T::test('preflight: dependencias remotas en HTML y CSS bloquean la activación', function () {
    $d = T::tmpDir();
    $page = fn(string $x) => "<!doctype html><title>t</title><link rel=\"canonical\" href=\"https://x.es/\">$x";
    file_put_contents("$d/index.html", $page('<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=X"><script src="//cdn.example/a.js"></script><img src="https://x.test/a.png"><a href="https://ok.example/">externo</a>'));
    file_put_contents("$d/404.html", $page(''));
    mkdir("$d/assets");
    file_put_contents("$d/assets/a.css", "@import url('b.css');");
    $e = implode("\n", (new Preflight())->check($d));
    T::contains('Dependencia remota (link', $e);
    T::contains('Dependencia remota (script', $e);
    T::contains('Dependencia remota (img', $e);
    T::contains('Dependencia remota en el CSS', $e);
    T::ok(!str_contains($e, 'canonical') && !str_contains($e, 'ok.example'), 'canónico y enlaces <a> externos son legítimos');
});
