<?php
declare(strict_types=1);

use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Database;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Core\Migrator;
use LuxAeterna\Core\TransientException;
use LuxAeterna\Content\ContentLoader;
use LuxAeterna\Publishing\JobStore;
use LuxAeterna\Publishing\PublishService;
use LuxAeterna\Publishing\Publisher;
use LuxAeterna\Publishing\ReleaseStore;

/** @param ?\Closure():SiteSnapshot $snap */
function lux_jobs(?Closure $snap = null, ?string $layout = null, array $pubCfg = []): stdClass
{
    $d = T::tmpDir();
    $root = dirname(__DIR__);
    $db = new Database("$d/lux.sqlite");
    (new Migrator($db, "$root/migrations"))->migrate();
    $c = Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://luxaeterna.es'], 'paths' => ['data' => "$d/var", 'publish' => "$d/pub"], 'publishing' => $pubCfg + ['activation' => 'symlink', 'keep_releases' => 5]], $root);
    $store = new ReleaseStore("$d/pub");
    $log = new Logger("$d/var/logs");
    $pub = new Publisher($c, $store, $log, $layout ?? "$root/components/layouts/base");
    $jobs = new JobStore($db);
    $snap ??= function () use ($root): SiteSnapshot {
        $pages = (new ContentLoader())->load("$root/examples/sitio-demo/content.json");
        return new SiteSnapshot($pages, []);
    };
    $s = new stdClass();
    $s->d = $d;
    $s->store = $store;
    $s->jobs = $jobs;
    $s->db = $db;
    $s->pub = $pub;
    $s->svc = new PublishService($c, $jobs, $store, $pub, $snap, $log);
    return $s;
}

T::test('trabajos: publicar encola, ejecuta y registra', function () {
    $s = lux_jobs();
    $id = $s->svc->enqueuePublish('cli', null, 'prueba');
    T::eq($id, $s->svc->enqueuePublish('cli', null), 'un segundo clic reutiliza el trabajo pendiente');
    $j = $s->svc->runNext($id);
    T::eq('succeeded', $j['status']);
    T::eq($j['release_id'], $s->store->currentId());
    T::ok(count($s->jobs->logs($id)) >= 2);
    $id2 = $s->svc->enqueuePublish('cli', null);
    $j2 = $s->svc->runNext($id2);
    T::eq('succeeded', $j2['status']);
    T::eq(1, (int)$j2['skipped'], 'contenido idéntico: omitido');
    T::eq(null, $s->svc->runNext(), 'cola vacía');
});
T::test('trabajos: fallo permanente deja la versión activa y falla sin reintentos', function () {
    $bad = false;
    $s = lux_jobs(function () use (&$bad) {
        if ($bad) {
            throw new LuxException('Configuración errónea.');
        }
        $pages = (new ContentLoader())->load(dirname(__DIR__) . '/examples/sitio-demo/content.json');
        return new SiteSnapshot($pages, []);
    });
    $s->svc->runNext($s->svc->enqueuePublish('cli', null));
    $active = $s->store->currentId();
    $bad = true;
    $j = $s->svc->runNext($s->svc->enqueuePublish('cli', null, '', true));
    T::eq('failed', $j['status']);
    T::eq(1, (int)$j['attempts']);
    T::contains('Configuración errónea', (string)$j['error']);
    T::eq($active, $s->store->currentId());
    T::ok(is_file("$s->d/pub/current/index.html"));
});
T::test('trabajos: fallo transitorio se reintenta de forma acotada', function () {
    $s = lux_jobs(function () {
        throw new TransientException('Disco ocupado.');
    });
    $id = $s->svc->enqueuePublish('cli', null);
    $j = $s->svc->runNext($id);
    T::eq('queued', $j['status']);
    T::ok((int)$j['not_before'] > time(), 'espera antes de reintentar');
    T::eq(null, $s->svc->runNext($id), 'no se reintenta antes de tiempo');
    for ($i = 2; $i <= 3; $i++) {
        $s->db->run('UPDATE publish_jobs SET not_before=0 WHERE id=?', [$id]);
        $j = $s->svc->runNext($id);
    }
    T::eq('failed', $j['status']);
    T::eq(3, (int)$j['attempts']);
});
T::test('trabajos: doble publicación simultánea no corrompe y el segundo trabajo se reintenta', function () {
    $s = lux_jobs();
    mkdir("$s->d/pub", 0755, true);
    $fh = fopen("$s->d/pub/.publish.lock", 'c');
    flock($fh, LOCK_EX);
    $id = $s->svc->enqueuePublish('panel', 1);
    $j = $s->svc->runNext($id);
    T::eq('queued', $j['status'], 'bloqueo ocupado → transitorio');
    flock($fh, LOCK_UN);
    fclose($fh);
    $s->db->run('UPDATE publish_jobs SET not_before=0');
    T::eq('succeeded', $s->svc->runNext($id)['status']);
});
T::test('trabajos: un trabajo «en ejecución» huérfano se recupera', function () {
    $s = lux_jobs();
    $s->store->init();
    $id = $s->svc->enqueuePublish('cli', null);
    $s->jobs->claim($id);
    $s->db->run('UPDATE publish_jobs SET heartbeat_at=heartbeat_at-1000 WHERE id=?', [$id]);
    T::eq(1, $s->jobs->recoverStale(fn() => true));
    T::eq('queued', $s->jobs->get($id)['status']);
    $j = $s->svc->runNext($id);
    T::eq('succeeded', $j['status']);
    T::eq(2, (int)$j['attempts']);
});
T::test('trabajos: con el bloqueo tomado no se da por muerto un trabajo en curso', function () {
    $s = lux_jobs();
    $id = $s->svc->enqueuePublish('cli', null);
    $s->jobs->claim($id);
    $s->db->run('UPDATE publish_jobs SET heartbeat_at=heartbeat_at-1000 WHERE id=?', [$id]);
    T::eq(0, $s->jobs->recoverStale(fn() => false));
    T::eq('running', $s->jobs->get($id)['status']);
});
T::test('trabajos: reversión valida la versión y conserva la activa si es inválida', function () {
    $s = lux_jobs();
    $s->svc->runNext($s->svc->enqueuePublish('cli', null));
    $a = $s->store->currentId();
    $s->svc->runNext($s->svc->enqueuePublish('cli', null, '', true));
    $b = $s->store->currentId();
    T::ok($a !== $b);
    $j = $s->svc->runNext($s->svc->enqueueRollback($a, 'panel', 1));
    T::eq('succeeded', $j['status']);
    T::eq($a, $s->store->currentId());
    $j = $s->svc->runNext($s->svc->enqueueRollback('20200101000000000000-abcd', 'panel', 1));
    T::eq('failed', $j['status']);
    T::eq($a, $s->store->currentId());
    unlink("$s->d/pub/releases/$b/404.html");
    $j = $s->svc->runNext($s->svc->enqueueRollback($b, 'panel', 1));
    T::eq('failed', $j['status'], 'versión incompleta rechazada');
    T::eq($a, $s->store->currentId());
    T::throws(LuxException::class, fn() => $s->svc->enqueueRollback('../x', 'panel', 1), 'Identificador');
});
