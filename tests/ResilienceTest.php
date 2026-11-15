<?php
declare(strict_types=1);

use LuxAeterna\Content\ContentLoader;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Fs;

/** Copia la plantilla base y sustituye style.css por una tubería: el proceso que la lea se bloquea ahí (con el bloqueo de publicación tomado). */
function lux_blocking_layout(string $d): string
{
    $dst = "$d/comp/layouts/base";
    mkdir($dst, 0755, true);
    exec('cp -a ' . escapeshellarg(dirname(__DIR__) . '/components/cabeceras') . ' ' . escapeshellarg(dirname(__DIR__) . '/components/footers') . ' ' . escapeshellarg(dirname(__DIR__) . '/components/barras') . ' ' . escapeshellarg(dirname(__DIR__) . '/components/heroes') . ' ' . escapeshellarg(dirname(__DIR__) . '/components/fonts') . ' ' . escapeshellarg("$d/comp"));
    copy(dirname(__DIR__) . '/components/layouts/base/template.html', "$dst/template.html");
    posix_mkfifo("$dst/style.css", 0644);
    return $dst;
}

/** @return array{proc:resource,pipes:array,pid:int} */
function lux_spawn_worker(string $d, string $layout, int $job): array
{
    $p = proc_open(['php', __DIR__ . '/fixtures/worker.php', $d, $layout, (string)$job], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $st = proc_get_status($p);
    return ['proc' => $p, 'pipes' => $pipes, 'pid' => $st['pid']];
}

function lux_wait_lock(stdClass $s, bool $taken, int $ms = 5000): bool
{
    for ($t = 0; $t < $ms; $t += 50) {
        if ($s->store->lockFree() !== $taken) {
            return true;
        }
        usleep(50000);
    }
    return false;
}

T::test('resiliencia: kill -9 durante la publicación → el sitio activo sigue, el bloqueo se libera y el trabajo se recupera', function () {
    $d0 = T::tmpDir();
    $s = lux_jobs(null, null, ['job_grace' => 0]);
    // versión buena publicada primero
    $s->svc->runNext($s->svc->enqueuePublish('cli', null));
    $good = $s->store->currentId();
    $before = file_get_contents("$s->d/pub/current/index.html");
    $layout = lux_blocking_layout($s->d);
    $id = $s->svc->enqueuePublish('cli', null, '', true);
    $w = lux_spawn_worker($s->d, $layout, $id);
    T::ok(lux_wait_lock($s, true), 'el trabajador toma el bloqueo');
    T::eq('running', $s->jobs->get($id)['status']);
    T::eq($good, $s->store->currentId());
    T::eq($before, file_get_contents("$s->d/pub/current/index.html"), 'el sitio activo se sirve mientras se publica');
    posix_kill($w['pid'], SIGKILL);
    proc_close($w['proc']);
    T::ok($s->store->lockFree(), 'el sistema operativo libera el bloqueo del proceso muerto');
    T::eq($good, $s->store->currentId(), 'la versión activa no cambia');
    T::eq($before, file_get_contents("$s->d/pub/current/index.html"));
    $s->db->run('UPDATE publish_jobs SET heartbeat_at = heartbeat_at - 10 WHERE id = ?', [$id]);
    $j = $s->svc->runNext($id);
    T::eq('succeeded', $j['status'], 'el trabajo huérfano se reencola y termina');
    T::eq(2, (int)$j['attempts']);
    T::ok($s->store->currentId() !== $good);
    T::ok(!glob("$s->d/pub/releases/.building-*"));
});
T::test('resiliencia: doble publicación simultánea (dos procesos) → una espera y se reintenta, nada se corrompe', function () {
    $s = lux_jobs();
    $s->svc->runNext($s->svc->enqueuePublish('cli', null));
    $layout = lux_blocking_layout($s->d);
    $a = $s->svc->enqueuePublish('cli', null, '', true);
    $w = lux_spawn_worker($s->d, $layout, $a);
    T::ok(lux_wait_lock($s, true));
    $b = $s->svc->enqueuePublish('panel', 1, '', false);
    $second = $s->svc->runNext($b);
    T::eq('queued', $second['status'], 'la segunda publicación espera');
    T::contains('en curso', (string)$second['error']);
    // desbloquea al primero entregando la hoja de estilos
    $fh = fopen("$layout/style.css", 'w');
    fwrite($fh, 'body{}');
    fclose($fh);
    stream_get_contents($w['pipes'][1]);
    proc_close($w['proc']);
    T::eq('succeeded', $s->jobs->get($a)['status']);
    $s->db->run('UPDATE publish_jobs SET not_before = 0 WHERE id = ?', [$b]);
    $j = $s->svc->runNext($b);
    T::eq('succeeded', $j['status']);
    T::ok(is_file("$s->d/pub/current/index.html"));
    T::eq([], array_filter(glob("$s->d/pub/releases/*") ?: [], fn($p) => !is_file("$p/index.html")), 'toda versión guardada está completa');
});
T::test('resiliencia: disco casi lleno (tmpfs pequeño) → la publicación falla limpia y el sitio sigue sirviéndose', function () {
    $d = T::tmpDir();
    mkdir("$d/mnt", 0755, true);
    exec('mount -t tmpfs -o size=1m tmpfs ' . escapeshellarg("$d/mnt") . ' 2>&1', $o, $rc);
    if ($rc !== 0) {
        T::ok(true, 'sin permiso para montar tmpfs: se omite');
        return;
    }
    try {
        $root = dirname(__DIR__);
        $c = LuxAeterna\Core\Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://luxaeterna.es'], 'paths' => ['data' => "$d/var", 'publish' => "$d/mnt/pub"], 'publishing' => ['activation' => 'symlink', 'min_free_mb' => 0]], $root);
        $store = new LuxAeterna\Publishing\ReleaseStore("$d/mnt/pub");
        $log = new LuxAeterna\Core\Logger("$d/var/logs");
        $pub = new LuxAeterna\Publishing\Publisher($c, $store, $log, "$root/components/layouts/base");
        $pages = (new ContentLoader())->load("$root/examples/sitio-demo/content.json");
        $ok = $pub->publishSnapshot(new SiteSnapshot($pages, []))['id'];
        $big = new SiteSnapshot($pages, []);
        // llena el volumen salvo unos KB
        $fill = 0;
        while (($free = disk_free_space("$d/mnt")) > 20000) {
            file_put_contents("$d/mnt/relleno" . $fill++, str_repeat('x', (int)min($free - 10000, 200000)));
        }
        $failed = false;
        try {
            $pub->publishSnapshot($big, ['force' => true]);
        } catch (Throwable $e) {
            $failed = true;
        }
        T::ok($failed, 'la publicación falla con el disco lleno');
        T::eq($ok, $store->currentId(), 'la versión activa no cambia');
        T::ok(is_file("$d/mnt/pub/current/index.html"));
        T::ok(!glob("$d/mnt/pub/releases/.building-*"), 'no quedan restos de la construcción fallida');
        T::eq([$ok], $store->stored());
    } finally {
        exec('umount ' . escapeshellarg("$d/mnt") . ' 2>&1');
    }
});
T::test('resiliencia: error de configuración (plantilla inexistente) → trabajo fallido, sitio activo intacto y versión previa restaurable', function () {
    $s = lux_jobs();
    $s->svc->runNext($s->svc->enqueuePublish('cli', null));
    $good = $s->store->currentId();
    // mismo almacenamiento y base de datos, plantilla rota
    $root = dirname(__DIR__);
    $c = LuxAeterna\Core\Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://luxaeterna.es'], 'paths' => ['data' => "$s->d/var", 'publish' => "$s->d/pub"], 'publishing' => ['activation' => 'symlink']], $root);
    $log = new LuxAeterna\Core\Logger("$s->d/var/logs");
    $svc = new LuxAeterna\Publishing\PublishService($c, $s->jobs, $s->store, new LuxAeterna\Publishing\Publisher($c, $s->store, $log, "$s->d/no-existe"),
        fn() => new SiteSnapshot((new ContentLoader())->load("$root/examples/sitio-demo/content.json"), []), $log);
    $j = $svc->runNext($svc->enqueuePublish('cli', null, '', true));
    T::eq('failed', $j['status']);
    T::eq($good, $s->store->currentId());
    T::ok(is_file("$s->d/pub/current/index.html"));
    T::ok(!glob("$s->d/pub/releases/.building-*"));
    // y se puede publicar bien después y revertir
    $s->svc->runNext($s->svc->enqueuePublish('cli', null, '', true));
    $new = $s->store->currentId();
    T::eq('succeeded', $s->svc->runNext($s->svc->enqueueRollback($good, 'cli', null))['status']);
    T::eq($good, $s->store->currentId());
    T::ok(is_dir("$s->d/pub/releases/$new"));
});
T::test('resiliencia: dependencia inexistente (enlace interno roto) → la comprobación previa bloquea y el sitio activo no cambia', function () {
    $s = lux_jobs();
    $s->svc->runNext($s->svc->enqueuePublish('cli', null));
    $good = $s->store->currentId();
    $root = dirname(__DIR__);
    $c = LuxAeterna\Core\Config::fromArray(['schema' => 1, 'site' => ['base_url' => 'https://luxaeterna.es'], 'paths' => ['data' => "$s->d/var", 'publish' => "$s->d/pub"], 'publishing' => ['activation' => 'symlink']], $root);
    $log = new LuxAeterna\Core\Logger("$s->d/var/logs");
    $snap = fn() => new SiteSnapshot((new ContentLoader())->load("$root/examples/sitio-demo/content.json"), ['main' => [['label' => 'Roto', 'url' => '/no-existe/']]]);
    $svc = new LuxAeterna\Publishing\PublishService($c, $s->jobs, $s->store, new LuxAeterna\Publishing\Publisher($c, $s->store, $log, "$root/components/layouts/base"), $snap, $log);
    $j = $svc->runNext($svc->enqueuePublish('cli', null, '', true));
    T::eq('failed', $j['status']);
    T::contains('Enlace interno roto /no-existe/', (string)$j['error'] . json_encode($s->jobs->logs((int)$j['id'])) . '', 'el motivo consta en el registro') ;
    T::eq($good, $s->store->currentId());
    T::ok(!glob("$s->d/pub/releases/.building-*"));
});
T::test('exportación: una versión copiada a otro directorio funciona bajo un servidor estático (todos los enlaces internos resuelven)', function () {
    $s = lux_jobs();
    $s->svc->runNext($s->svc->enqueuePublish('cli', null));
    $id = $s->store->currentId();
    $out = T::tmpDir() . '/export';
    mkdir($out, 0755, true);
    exec('cp -a ' . escapeshellarg("$s->d/pub/releases/$id") . '/. ' . escapeshellarg($out));
    $port = random_int(20000, 40000);
    $p = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $out], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(500000);
    try {
        $fetch = function (string $path) use ($port): array {
            $h = @file_get_contents("http://127.0.0.1:$port$path", false, stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]));
            preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
            return [(int)($m[1] ?? 0), (string)$h];
        };
        [$code, $home] = $fetch('/');
        T::eq(200, $code);
        preg_match_all('/(?:href|src)="(\/[^"#?]*)"/', $home, $links);
        T::ok(count($links[1]) > 0);
        foreach (array_unique($links[1]) as $u) {
            T::eq(200, $fetch($u)[0], "enlace $u");
        }
    } finally {
        proc_terminate($p);
        proc_close($p);
    }
});
