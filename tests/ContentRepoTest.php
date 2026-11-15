<?php
declare(strict_types=1);

use LuxAeterna\Content\Blocks;
use LuxAeterna\Content\ConflictException;
use LuxAeterna\Content\ContentRepository;
use LuxAeterna\Content\Menus;
use LuxAeterna\Content\Settings;
use LuxAeterna\Content\Slug;
use LuxAeterna\Content\Taxonomy;
use LuxAeterna\Content\TypeRegistry;
use LuxAeterna\Core\Database;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Core\Migrator;

/** @return array{0:ContentRepository,1:Database,2:Taxonomy,3:TypeRegistry} */
function lux_repo(array $customTypes = []): array
{
    $d = T::tmpDir();
    $db = new Database("$d/db.sqlite");
    (new Migrator($db, dirname(__DIR__) . '/migrations'))->migrate();
    $types = new TypeRegistry($customTypes);
    $tax = new Taxonomy($db);
    return [new ContentRepository($db, $types, $tax), $db, $tax, $types];
}

function doc(string $title, string $body = 'Hola mundo', array $extra = []): array
{
    return ['title' => $title, 'blocks' => Blocks::fromText($body)] + $extra;
}

T::test('slug: normaliza tildes y símbolos; valida y reserva', function () {
    T::eq('canon-de-la-ñ-no', 'canon-de-la-ñ-no'); // marcador de que el archivo es UTF-8
    T::eq('cano-y-pinon-2026', Slug::normalize('  Cañó y Piñón — 2026! '));
    T::eq('', Slug::normalize('¡¿?!'));
    T::eq(null, Slug::validate('hola-mundo'));
    T::ok(Slug::validate('Hola') !== null);
    T::ok(Slug::validate('a--b') !== null);
    T::ok(Slug::validate('admin', ['admin']) !== null);
    T::ok(Slug::validate(str_repeat('a', 81)) !== null);
});
T::test('bloques: validación, texto↔estructura sin pérdidas y escapes de marcadores', function () {
    $txt = "## Título\n\nUn párrafo\ncon dos líneas.\n\n- uno\n- dos\n\n> una cita\n\n[imagen:foto-001|Un gato|Pie de foto]\n\n[galeria:viaje-2026]\n\n\\## no es título";
    $blocks = Blocks::fromText($txt);
    T::eq(['heading', 'paragraph', 'list', 'quote', 'image', 'gallery', 'paragraph'], array_column($blocks, 'type'));
    T::eq('Un párrafo con dos líneas.', $blocks[1]['text']);
    T::eq('## no es título', $blocks[6]['text']);
    T::eq($blocks, Blocks::fromText(Blocks::toText($blocks)), 'ida y vuelta');
    T::eq(['foto-001', 'viaje-2026'], Blocks::mediaRefs($blocks));
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'script', 'text' => 'x']]), 'tipo no soportado');
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'heading', 'level' => 1, 'text' => 'x']]));
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'image', 'ref' => '../../etc/passwd', 'alt' => '']]), 'referencia');
    T::throws(LuxException::class, fn() => Blocks::validate([['type' => 'paragraph', 'text' => "a\x00b"]]));
    T::throws(LuxException::class, fn() => Blocks::validate(array_fill(0, 501, ['type' => 'paragraph', 'text' => 'x'])));
    T::eq([], Blocks::fromText("  \n\n "));
});
T::test('tipos: integrados, campos configurables validados, prefijos reservados', function () {
    $t = new TypeRegistry(['servicio' => ['label' => 'Servicio', 'prefix' => 'servicios', 'fields' => [
        'precio' => ['type' => 'number', 'label' => 'Precio'],
        'zona' => ['type' => 'select', 'label' => 'Zona', 'options' => ['norte', 'sur'], 'required' => true],
        'urgente' => ['type' => 'bool', 'label' => 'Urgente'],
    ]]]);
    T::eq('/servicios/mi-servicio/', $t->path('servicio', 'mi-servicio'));
    T::eq('/blog/x/', $t->path('post', 'x'));
    T::eq('/', $t->path('page', ''));
    T::ok(in_array('servicios', $t->reservedForPages(), true) && in_array('blog', $t->reservedForPages(), true));
    T::eq(['precio' => 12.5, 'zona' => 'sur', 'urgente' => true], $t->validateFields('servicio', ['precio' => '12.5', 'zona' => 'sur', 'urgente' => 'on', 'extra' => 'ignorado']));
    T::throws(LuxException::class, fn() => $t->validateFields('servicio', ['zona' => 'este']), 'valor no permitido');
    T::throws(LuxException::class, fn() => $t->validateFields('servicio', ['precio' => 'caro', 'zona' => 'sur']), 'número');
    T::throws(LuxException::class, fn() => $t->validateFields('servicio', []), 'obligatorio');
    foreach ([['page' => []], ['x' => ['prefix' => 'admin']], ['y' => ['prefix' => 'blog']], ['Z' => []], ['w' => ['fields' => ['a' => ['type' => 'html']]]]] as $bad) {
        T::throws(LuxException::class, fn() => new TypeRegistry($bad));
    }
    T::throws(LuxException::class, fn() => new TypeRegistry(['a1' => ['prefix' => 'dup'], 'b1' => ['prefix' => 'dup']]));
});
T::test('contenido: crear, guardar, revisiones y restaurar crea una revisión nueva', function () {
    [$repo] = lux_repo();
    $id = $repo->create('page', doc('Sobre nosotros'), 1);
    $c = $repo->get($id);
    T::eq('sobre-nosotros', $c['slug']);
    T::eq('draft', $c['status']);
    T::eq(1, (int)$c['version']);
    $v = $repo->save($id, 1, doc('Sobre nosotros', 'Texto dos'), 1);
    T::eq(2, $v);
    $v = $repo->save($id, $v, doc('Sobre nosotros', 'Texto tres'), 1);
    T::eq(['Texto tres'], array_column($repo->get($id)['rev']['blocks'], 'text'));
    T::eq([3, 2, 1], array_column($repo->revisions($id), 'number'));
    $v2 = $repo->save($id, $v, doc('Sobre nosotros', 'Texto tres'), 1);
    T::eq($v, $v2, 'guardar sin cambios no crea revisión ni sube versión');
    $v = $repo->restoreRevision($id, 1, $v, 1);
    $c = $repo->get($id);
    T::eq(['Hola mundo'], array_column($c['rev']['blocks'], 'text'));
    T::eq([4, 3, 2, 1], array_column($repo->revisions($id), 'number'), 'restaurar añade, no reescribe');
    T::eq('restore', $c['rev']['kind']);
    T::eq('Texto dos', $repo->revisionByNumber($id, 2)['blocks'][0]['text']);
});
T::test('bloqueo optimista: la segunda escritura con versión vieja se rechaza y no pierde nada', function () {
    [$repo] = lux_repo();
    $id = $repo->create('page', doc('Contacto'), 1);
    $repo->save($id, 1, doc('Contacto', 'A'), 1);
    T::throws(ConflictException::class, fn() => $repo->save($id, 1, doc('Contacto', 'B'), 2));
    T::throws(ConflictException::class, fn() => $repo->publish($id, 1));
    T::throws(ConflictException::class, fn() => $repo->autosaveWrite($id, 1, doc('Contacto', 'C'), 2));
    T::eq(['A'], array_column($repo->get($id)['rev']['blocks'], 'text'));
});
T::test('autoguardado: no crea revisión ni sube versión; se descarta al guardar', function () {
    [$repo] = lux_repo();
    $id = $repo->create('page', doc('Inicio'), 1);
    T::eq(null, $repo->autosave($id));
    sleep(1);
    $repo->autosaveWrite($id, 1, doc('Inicio', 'Texto sin terminar'), 1);
    T::eq(1, (int)$repo->get($id)['version']);
    T::eq([1], array_column($repo->revisions($id), 'number'));
    T::eq('Texto sin terminar', $repo->autosave($id)['blocks'][0]['text']);
    $repo->autosaveWrite($id, 1, doc('Inicio', 'Texto más largo'), 1);
    T::eq('Texto más largo', $repo->autosave($id)['blocks'][0]['text'], 'solo hay un autoguardado');
    $repo->save($id, 1, doc('Inicio', 'Definitivo'), 1);
    T::eq(null, $repo->autosave($id));
});
T::test('publicación: la revisión publicada es independiente de la que se edita', function () {
    [$repo] = lux_repo();
    $id = $repo->create('page', doc('Servicios', 'Versión pública'), 1);
    $v = $repo->publish($id, 1);
    $c = $repo->get($id);
    T::eq('published', $c['status']);
    T::ok(!$c['dirty']);
    $v = $repo->save($id, $v, doc('Servicios', 'Borrador nuevo'), 1);
    $c = $repo->get($id);
    T::ok($c['dirty'], 'hay cambios sin publicar');
    T::eq('Versión pública', $c['pub']['blocks'][0]['text']);
    T::eq('Borrador nuevo', $c['rev']['blocks'][0]['text']);
    $v = $repo->unpublish($id, $v);
    $c = $repo->get($id);
    T::eq(['draft', null], [$c['status'], $c['pub']]);
});
T::test('slugs: colisiones, reservados y cambio de URL con redirección 301', function () {
    [$repo, $db] = lux_repo();
    $a = $repo->create('page', doc('Equipo'), 1);
    T::throws(LuxException::class, fn() => $repo->create('page', doc('Otro', 'x', ['slug' => 'equipo']), 1), 'otro contenido');
    T::throws(LuxException::class, fn() => $repo->create('page', doc('Admin'), 1), 'reservado');
    T::throws(LuxException::class, fn() => $repo->create('page', doc('Blog'), 1), 'reservado');
    $post = $repo->create('post', doc('Admin'), 1); // las entradas viven bajo /blog/, no chocan
    T::ok($post > 0);
    $v = $repo->publish($a, 1);
    $v = $repo->save($a, $v, doc('Equipo', 'x', ['slug' => 'nuestro-equipo']), 1);
    T::eq('equipo', $repo->get($a)['published_slug'], 'lo publicado mantiene su URL hasta publicar');
    $v = $repo->publish($a, $v);
    T::eq('/nuestro-equipo/', $db->val("SELECT to_path FROM redirects WHERE from_path = '/equipo/'"));
    // un cambio posterior encadena sin dejar saltos intermedios
    $v = $repo->save($a, $v, doc('Equipo', 'x', ['slug' => 'staff']), 1);
    $v = $repo->publish($a, $v);
    T::eq('/staff/', $db->val("SELECT to_path FROM redirects WHERE from_path = '/equipo/'"));
    T::eq('/staff/', $db->val("SELECT to_path FROM redirects WHERE from_path = '/nuestro-equipo/'"));
    // volver a un slug antiguo elimina la redirección que lo ocupaba
    $v = $repo->save($a, $v, doc('Equipo', 'x', ['slug' => 'equipo']), 1);
    $repo->publish($a, $v);
    T::eq(null, $db->val("SELECT 1 FROM redirects WHERE from_path = '/equipo/'"));
    T::eq(null, $db->val("SELECT 1 FROM redirects WHERE from_path = to_path"));
});
T::test('papelera: retira del sitio, restaura a borrador, protege la portada y borra en cascada', function () {
    [$repo, $db] = lux_repo();
    $id = $repo->create('page', doc('Aviso legal'), 1);
    $v = $repo->publish($id, 1);
    $uid = $repo->get($id)['uid'];
    $menus = new Menus($db);
    $menus->add('main', 'Legal', $uid, null);
    T::throws(LuxException::class, fn() => $repo->trash($id, $v, $uid), 'portada');
    $v = $repo->trash($id, $v);
    $c = $repo->get($id);
    T::eq(['trashed', null, 'published'], [$c['status'], $c['published_rev'], $c['prev_status']]);
    T::throws(LuxException::class, fn() => $repo->save($id, $v, doc('Aviso legal', 'x'), 1), 'papelera');
    T::throws(LuxException::class, fn() => $repo->publish($id, $v));
    T::eq([], $repo->search(null, null), 'no sale en el listado normal');
    T::eq(1, count($repo->search(null, 'trashed')));
    // el slug queda libre mientras está en la papelera
    $otro = $repo->create('page', doc('Aviso legal'), 1);
    T::throws(LuxException::class, fn() => $repo->restoreFromTrash($id, $v), 'slug ocupado');
    $repo->trash($otro, 1);
    $v = $repo->restoreFromTrash($id, $v);
    T::eq('draft', $repo->get($id)['status'], 'restaurar no republica');
    T::throws(LuxException::class, fn() => $repo->deletePermanently($id), 'solo desde la papelera');
    $v = $repo->trash($id, $v);
    $repo->deletePermanently($id);
    T::eq(null, $repo->get($id));
    T::eq(0, count($menus->items('main')), 'se retira de los menús');
    T::eq(0, (int)$db->val('SELECT COUNT(*) FROM revisions WHERE content_id = ' . $id), 'revisiones borradas');
});
T::test('revisiones: se limita el historial pero se conservan la actual y la publicada', function () {
    [$repo, $db] = lux_repo();
    $id = $repo->create('page', doc('Larga'), 1);
    $v = $repo->publish($id, 1);
    for ($i = 0; $i < ContentRepository::MAX_REVISIONS + 5; $i++) {
        $v = $repo->save($id, $v, doc('Larga', "Texto $i"), 1);
    }
    $nums = array_column($repo->revisions($id), 'number');
    T::ok(count($nums) <= ContentRepository::MAX_REVISIONS + 1);
    T::ok(in_array(1, $nums, true), 'la publicada (1) se conserva');
    T::eq(ContentRepository::MAX_REVISIONS + 6, $nums[0]);
    T::ok($repo->get($id)['pub'] !== null);
});
T::test('taxonomías: categorías, etiquetas por nombre y asignación', function () {
    [$repo, , $tax] = lux_repo();
    $id = $repo->create('post', doc('Novedades'), 1);
    $cat = $tax->create('category', 'Noticias de León');
    T::eq($cat, $tax->create('category', 'noticias de leon'), 'mismo slug → mismo término');
    $tax->assign($id, [$cat, 9999], ['PHP', ' php ', 'Nginx', '']);
    T::eq([$cat], $tax->idsFor($id, 'category'));
    T::eq(2, count($tax->idsFor($id, 'tag')));
    $tax->assign($id, [], []);
    T::eq([], $tax->idsFor($id, 'tag'));
    T::throws(LuxException::class, fn() => $tax->create('tag', str_repeat('x', 81)));
    T::throws(LuxException::class, fn() => $tax->create('otra', 'x'));
});
T::test('menús: alta con contenido o URL, validación de URL y reordenación', function () {
    [$repo, $db] = lux_repo();
    $id = $repo->create('page', doc('Contacto'), 1);
    $uid = $repo->get($id)['uid'];
    $m = new Menus($db);
    $a = $m->add('main', 'Contacto', $uid, null);
    $b = $m->add('main', 'Tienda', null, 'https://tienda.example.org/');
    $c = $m->add('main', 'Interno', null, '/blog/');
    T::eq([$a, $b, $c], array_map('intval', array_column($m->items('main'), 'id')));
    $m->move($c, -1);
    T::eq([$a, $c, $b], array_map('intval', array_column($m->items('main'), 'id')));
    $m->move($a, -1);
    T::eq($a, (int)$m->items('main')[0]['id'], 'el primero no sube más');
    foreach (['javascript:alert(1)', '//evil.example', 'http://insegura.example', '/x y', "https://a.example/\"onload"] as $bad) {
        T::throws(LuxException::class, fn() => $m->add('main', 'Mal', null, $bad), 'dirección');
    }
    T::throws(LuxException::class, fn() => $m->add('main', 'Mal', 'c_inexistente', null));
    T::throws(LuxException::class, fn() => $m->add('main', 'Mal', $uid, '/x/'));
    T::throws(LuxException::class, fn() => $m->add('otro', 'Mal', null, '/x/'));
});
T::test('ajustes: guardar, leer y borrar', function () {
    [, $db] = lux_repo();
    $s = new Settings($db);
    T::eq(null, $s->get('home'));
    $s->set('home', 'c_1');
    $s->set('home', 'c_2');
    T::eq('c_2', $s->get('home'));
    $s->set('home', null);
    T::eq('x', $s->get('home', 'x'));
});
T::test('tipos propios: los campos se guardan por revisión y se validan al guardar', function () {
    [$repo] = lux_repo(['servicio' => ['fields' => ['precio' => ['type' => 'number', 'label' => 'Precio']]]]);
    $id = $repo->create('servicio', doc('Cerrajería 24h', 'x', ['fields' => ['precio' => '49']]), 1);
    T::eq(49, $repo->get($id)['rev']['fields']['precio']);
    T::throws(LuxException::class, fn() => $repo->save($id, 1, doc('Cerrajería 24h', 'x', ['fields' => ['precio' => 'mucho']]), 1));
    T::throws(LuxException::class, fn() => $repo->create('inventado', doc('x'), 1));
});
