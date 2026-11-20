<?php
declare(strict_types=1);

use CMSModular\Publishing\RuleBuilder;

/** Ejecuta deploy/lux-apply-rules.sh con nginx y recarga simulados. @return array{code:int,status:array,out:string} */
function lux_apply(string $rules, bool $nginxOk = true, bool $reloadOk = true, ?string $prevOut = null): array
{
    $d = T::tmpDir();
    $base = "$d/sitio.test";
    mkdir("$base/publish/rules", 0755, true);
    file_put_contents("$base/publish/rules/redirects.nginx.conf", $rules);
    file_put_contents("$base/publish/rules/apply.request", "abc123 20261115000000000000-aaaa\n");
    file_put_contents("$d/nginx", "#!/bin/sh\nexit " . ($nginxOk ? 0 : 1) . "\n");
    file_put_contents("$d/reload", "#!/bin/sh\nexit " . ($reloadOk ? 0 : 1) . "\n");
    chmod("$d/nginx", 0755);
    chmod("$d/reload", 0755);
    if ($prevOut !== null) {
        file_put_contents("$d/out.conf", $prevOut);
    }
    $env = ['PATH' => getenv('PATH'), 'NGINX_BIN' => "$d/nginx", 'RELOAD_CMD' => "$d/reload", 'OUT_FILE' => "$d/out.conf", 'STATE_FILE' => "$d/state.json", 'LUX_DOMAIN' => 'sitio.test'];
    $p = proc_open(['bash', dirname(__DIR__) . '/deploy/lux-apply-rules.sh', $base], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'status' => json_decode((string)@file_get_contents("$d/state.json"), true) ?: [], 'out' => $out, 'file' => (string)@file_get_contents("$d/out.conf")];
}

T::test('reglas: RuleBuilder genera solo rutas válidas, ordenadas y sin autorredirecciones', function () {
    $rules = RuleBuilder::clean([
        ['from' => '/b/', 'to' => '/c/'], ['from' => '/a/', 'to' => '/c/'], ['from' => '/x/', 'to' => '/x/'],
        ['from' => '/mal;/', 'to' => '/c/'], ['from' => '/a b/', 'to' => '/c/'], ['from' => '/', 'to' => '/c/'], ['from' => '/ok/', 'to' => '/de st/'],
    ]);
    T::eq(['/a/', '/b/'], array_column($rules, 'from'));
    $n = RuleBuilder::nginx($rules);
    T::contains("location = /a/ { return 301 /c/; }", $n);
    T::ok(!str_contains($n, 'mal'));
    $a = RuleBuilder::apache($rules, "Options -Indexes");
    T::contains('RedirectMatch 301 "^/a/$" "/c/"', $a);
    T::contains('Options -Indexes', $a);
});
T::test('reglas: el servicio instala reglas válidas y recarga', function () {
    $r = lux_apply("# c\nlocation = /a/ { return 301 /b/; }\nlocation = /x-y/z/ { return 301 /w/; }\n");
    T::eq(0, $r['code']);
    T::ok($r['status']['ok']);
    T::eq(2, $r['status']['count']);
    T::eq('abc123', $r['status']['token']);
    T::contains('location = /a/', $r['file']);
});
T::test('reglas: el servicio rechaza inyección de configuración y no instala nada', function () {
    foreach ([
        "location = /a/ { return 301 /b/; }\nlocation / { proxy_pass http://evil; }\n",
        "location = /a/ { return 301 /b/; } include /etc/passwd;\n",
        "location = /a/ { return 301 https://evil.example/; }\n",
        "location = /../a/ { return 301 /b/; }\n",
        "server { listen 1; }\n",
        "location = /a/ { return 301 /b/; }\r\n",
    ] as $bad) {
        $r = lux_apply($bad, true, true, "# previo\n");
        T::eq(1, $r['code'], 'rechazada: ' . trim($bad));
        T::ok($r['status']['ok'] === false);
        T::eq("# previo\n", $r['file'], 'la configuración vigente no cambia');
        T::ok(!str_contains(json_encode($r['status']), 'evil'), 'el estado no repite contenido ajeno');
    }
});
T::test('reglas: si nginx -t falla se restauran las reglas anteriores', function () {
    $r = lux_apply("location = /a/ { return 301 /b/; }\n", false, true, "# previo\n");
    T::eq(1, $r['code']);
    T::eq("# previo\n", $r['file']);
    T::contains('restauraron', $r['status']['message']);
});
T::test('reglas: si la recarga falla se informa', function () {
    $r = lux_apply("location = /a/ { return 301 /b/; }\n", true, false);
    T::eq(1, $r['code']);
    T::ok($r['status']['ok'] === false);
});
