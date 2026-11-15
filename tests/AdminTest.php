<?php
declare(strict_types=1);

use LuxAeterna\Admin\App;
use LuxAeterna\Admin\Request;
use LuxAeterna\Admin\Response;
use LuxAeterna\Admin\Services;
use LuxAeterna\Core\Config;
use LuxAeterna\Security\Totp;

const PW = 'Un-Buen_Pasaje.Largo9';

/** Cliente de pruebas con jar de cookies. */
final class Client
{
    public array $cookies = [];
    public ?Response $last = null;

    public function __construct(public Services $sv, public string $ip = '203.0.113.7')
    {
    }

    public function req(string $method, string $path, array $post = [], array $headers = [], array $query = [], array $files = []): Response
    {
        $post = self::wire($post);
        $h = ['user-agent' => 'Mozilla/5.0 (iPhone) Safari'] + $headers;
        if ($method === 'POST' && !isset($h['origin'])) {
            $h['origin'] = 'https://luxaeterna.es';
        }
        $res = (new App($this->sv))->handle(new Request($method, $path, $query, $post, $this->cookies, $h, $this->ip, $files));
        if ($res->status === 500) { // un 500 inesperado en pruebas: mostrar el detalle del log privado
            $log = (string)shell_exec('tail -n 3 ' . escapeshellarg($this->sv->config->path('data') . '/logs') . '/*.log 2>/dev/null');
            throw new \RuntimeException("HTTP 500 en $method $path: $log");
        }
        foreach ($res->cookies as $c) {
            [$nv] = explode(';', $c, 2);
            [$n, $v] = explode('=', $nv, 2);
            if ($v === '') {
                unset($this->cookies[$n]);
            } else {
                $this->cookies[$n] = $v;
            }
        }
        return $this->last = $res;
    }

    /** Un navegador envía siempre cadenas: reproduce eso en las pruebas. */
    private static function wire(array $v): array
    {
        return array_map(static fn($x) => is_array($x) ? self::wire($x) : (is_bool($x) ? ($x ? '1' : '0') : (string)$x), $v);
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->req('GET', $path, [], [], $query);
    }

    public function csrfFrom(Response $r): string
    {
        preg_match('/name="csrf" value="([^"]+)"/', $r->body, $m);
        return $m[1] ?? '';
    }

    public function post(string $path, array $post, ?string $csrfFromPath = null): Response
    {
        if (!isset($post['csrf'])) {
            $post['csrf'] = $this->csrfFrom($this->get($csrfFromPath ?? $path));
        }
        return $this->req('POST', $path, $post);
    }

    /** Subida multipart simulada: $files = campo => [[nombre, ruta temporal], ...] */
    public function upload(string $path, array $files, string $csrfFromPath, array $post = []): Response
    {
        $f = [];
        foreach ($files as $field => $list) {
            foreach ($list as [$name, $tmp]) {
                $f[$field][] = ['name' => $name, 'tmp_name' => $tmp, 'size' => (int)@filesize($tmp), 'error' => 0];
            }
        }
        $post['csrf'] ??= $this->csrfFrom($this->get($csrfFromPath));
        return $this->req('POST', $path, $post, [], [], $f);
    }

    public function login(string $email, string $pw): Response
    {
        return $this->post('/admin/login', ['email' => $email, 'password' => $pw]);
    }
}

function lux_app(array $cfg = []): array
{
    $d = T::tmpDir();
    foreach (['db', 'secrets', 'logs'] as $s) {
        mkdir("$d/$s", 0750, true);
    }
    $conf = Config::fromArray(array_replace_recursive([
        'schema' => 1,
        'site' => ['base_url' => 'https://luxaeterna.es'],
        'paths' => ['data' => $d, 'publish' => "$d/publish"],
        'admin' => ['prefix' => '/admin'],
    ], $cfg), dirname(__DIR__));
    return [new Services($conf, true), $d];
}

/** Crea usuario activo con contraseña (y opcionalmente MFA activo). Devuelve [id, secretoTotp|null]. */
function lux_user(Services $sv, string $email, string $role = 'admin', bool $mfa = false): array
{
    $id = $sv->users->create($email, $role);
    $sv->users->setPassword($id, PW);
    $secret = null;
    if ($mfa) {
        $secret = Totp::generateSecret();
        $sv->users->stageTotp($id, $secret);
        $sv->users->activateTotp($id, 0);
    }
    return [$id, $secret];
}

function nextCode(string $secret, int $afterStep): string
{
    // Siguiente paso de 30 s válido (el servidor acepta ±1 ventana y exige paso creciente).
    return Totp::code($secret, max(intdiv(time(), 30), $afterStep + 1));
}

T::test('panel: sin sesión todo redirige al login y no filtra contenido', function () {
    [$sv] = lux_app();
    $c = new Client($sv);
    foreach (['/admin/', '/admin/account', '/admin/audit', '/admin/account/mfa'] as $p) {
        $r = $c->get($p);
        T::eq(303, $r->status, $p);
        T::eq('/admin/login', $r->headers['Location'], $p);
    }
    foreach (['/admin/logout', '/admin/account/password', '/admin/account/sessions/revoke'] as $p) {
        T::eq(303, $c->req('POST', $p, ['csrf' => 'x'])->status, "POST $p");
    }
    T::eq(404, $c->get('/otra/cosa')->status);
    T::eq(405, $c->req('DELETE', '/admin/')->status);
});
T::test('panel: cabeceras de seguridad y cookie con atributos correctos', function () {
    [$sv] = lux_app();
    lux_user($sv, 'a@b.es', 'admin', false);
    $c = new Client($sv);
    $r = $c->get('/admin/login');
    T::eq(200, $r->status);
    T::contains("default-src 'none'", $r->headers['Content-Security-Policy']);
    T::contains("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
    T::contains("form-action 'self'", $r->headers['Content-Security-Policy']);
    T::ok(!str_contains($r->headers['Content-Security-Policy'], 'unsafe'), 'sin unsafe-*');
    T::eq('nosniff', $r->headers['X-Content-Type-Options']);
    T::eq('no-store', $r->headers['Cache-Control']);
    T::eq('DENY', $r->headers['X-Frame-Options']);
    T::ok(isset($r->headers['Strict-Transport-Security']));
    T::ok(!str_contains($r->body, '<script') && !str_contains($r->body, 'style="'), 'sin JS ni estilos en línea');
    $r = $c->login('a@b.es', PW);
    $cookie = $r->cookies[0];
    T::contains('__Secure-lux_sid=', $cookie);
    foreach (['HttpOnly', 'Secure', 'SameSite=Strict', 'Path=/admin'] as $attr) {
        T::contains($attr, $cookie);
    }
});
T::test('login: usuario inexistente, inactivo y contraseña errónea dan la misma respuesta', function () {
    [$sv] = lux_app();
    lux_user($sv, 'a@b.es');
    $sv->users->create('invitado@b.es', 'editor');
    $bodies = [];
    foreach ([['a@b.es', 'incorrecta-123456'], ['noexiste@b.es', PW], ['invitado@b.es', PW]] as [$e, $p]) {
        $c = new Client($sv, '198.51.100.' . count($bodies));
        $r = $c->login($e, $p);
        T::eq(401, $r->status);
        $bodies[] = preg_replace('/name="csrf" value="[^"]+"|value="[^"]*"/', '', $r->body);
        T::eq([], $c->cookies, 'no se emite cookie en fallo');
    }
    T::eq($bodies[0], $bodies[1]);
    T::eq($bodies[0], $bodies[2]);
});
T::test('login: limita intentos por cuenta y por IP sin revelar si la cuenta existe', function () {
    [$sv] = lux_app();
    lux_user($sv, 'a@b.es');
    $c = new Client($sv);
    for ($i = 0; $i < 5; $i++) {
        T::eq(401, $c->login('a@b.es', 'mala-contraseña-' . $i)->status);
    }
    $r = $c->login('a@b.es', PW);
    T::eq(401, $r->status, 'bloqueada incluso con la contraseña correcta');
    T::eq([], $c->cookies);
    T::ok($sv->db->val("SELECT COUNT(*) FROM audit WHERE event = 'login.blocked'") >= 1);
    // otra IP sigue pudiendo atacar otras cuentas, pero un bloqueo por IP corta el abuso masivo
    $c2 = new Client($sv, '192.0.2.9');
    for ($i = 0; $i < 20; $i++) {
        $c2->login("x$i@b.es", 'mala-contraseña');
    }
    T::eq(401, $c2->login('otra@b.es', 'mala-contraseña')->status);
    $before = (int)$sv->db->val("SELECT COUNT(*) FROM audit WHERE event = 'login.blocked'");
    $c2->login('otra2@b.es', 'x');
    T::ok((int)$sv->db->val("SELECT COUNT(*) FROM audit WHERE event = 'login.blocked'") > $before, 'IP bloqueada');
});
T::test('login: sin MFA configurado y MFA obligatorio fuerza a configurarlo antes de usar el panel', function () {
    [$sv] = lux_app();
    lux_user($sv, 'a@b.es');
    $c = new Client($sv);
    $r = $c->login('a@b.es', PW);
    T::eq('/admin/', $r->headers['Location']);
    foreach (['/admin/', '/admin/account', '/admin/audit'] as $p) {
        T::eq('/admin/account/mfa', $c->get($p)->headers['Location'], $p);
    }
    $r = $c->get('/admin/account/mfa');
    T::eq(200, $r->status);
    preg_match('/<code>([A-Z2-7 ]+)<\/code>/', $r->body, $m);
    $secret = str_replace(' ', '', $m[1]);
    T::eq(32, strlen($secret));
    $bad = $c->post('/admin/account/mfa', ['code' => '000000'], '/admin/account/mfa');
    T::eq(422, $bad->status);
    $ok = $c->post('/admin/account/mfa', ['code' => Totp::code($secret, intdiv(time(), 30))], '/admin/account/mfa');
    T::eq(200, $ok->status);
    T::contains('Códigos de recuperación', $ok->body);
    T::eq(10, preg_match_all('/<li><code>[a-z2-9]{5}-[a-z2-9]{5}<\/code><\/li>/', $ok->body));
    T::eq(200, $c->get('/admin/account')->status, 'ya con MFA activo se accede al panel');
    T::ok(!str_contains((string)$sv->db->val('SELECT meta FROM audit WHERE event = "mfa.enabled"'), $secret));
});
T::test('login con MFA: contraseña sola no basta; código válido sí; no se reutiliza; recuperación de un solo uso', function () {
    [$sv] = lux_app();
    [$id, $secret] = lux_user($sv, 'a@b.es', 'admin', true);
    $codes = $sv->users->generateRecoveryCodes($id);
    $c = new Client($sv);
    $r = $c->login('a@b.es', PW);
    T::eq('/admin/login/mfa', $r->headers['Location']);
    T::eq('/admin/login', $c->get('/admin/')->headers['Location'], 'sesión pendiente no da acceso al panel');
    T::eq('/admin/login', $c->get('/admin/account')->headers['Location']);
    T::eq(401, $c->post('/admin/login/mfa', ['code' => '123456'], '/admin/login/mfa')->status);
    $good = nextCode($secret, 0);
    $r = $c->post('/admin/login/mfa', ['code' => $good], '/admin/login/mfa');
    T::eq('/admin/', $r->headers['Location']);
    T::eq(200, $c->get('/admin/')->status);
    // segundo dispositivo intenta reutilizar el mismo código
    $c2 = new Client($sv, '198.51.100.50');
    $c2->login('a@b.es', PW);
    T::eq(401, $c2->post('/admin/login/mfa', ['code' => $good], '/admin/login/mfa')->status, 'código ya usado');
    $r = $c2->post('/admin/login/mfa', ['code' => $codes[0]], '/admin/login/mfa');
    T::eq('/admin/', $r->headers['Location'], 'recuperación válida');
    $c3 = new Client($sv, '198.51.100.51');
    $c3->login('a@b.es', PW);
    T::eq(401, $c3->post('/admin/login/mfa', ['code' => $codes[0]], '/admin/login/mfa')->status, 'recuperación de un solo uso');
});
T::test('login con MFA: límite de intentos del segundo factor', function () {
    [$sv] = lux_app();
    [, $secret] = lux_user($sv, 'a@b.es', 'admin', true);
    $c = new Client($sv);
    $c->login('a@b.es', PW);
    for ($i = 0; $i < 5; $i++) {
        T::eq(401, $c->post('/admin/login/mfa', ['code' => sprintf('%06d', $i)], '/admin/login/mfa')->status);
    }
    $r = $c->post('/admin/login/mfa', ['code' => nextCode($secret, 0)], '/admin/login/mfa');
    T::eq(401, $r->status, 'bloqueado aunque el código sea correcto');
    T::contains('Demasiados intentos', $r->body);
});
T::test('CSRF y origen: peticiones falsificadas son rechazadas', function () {
    [$sv] = lux_app();
    lux_user($sv, 'a@b.es', 'admin', true)[0];
    $c = new Client($sv);
    // login sin token
    T::eq(401, $c->req('POST', '/admin/login', ['email' => 'a@b.es', 'password' => PW])->status);
    T::eq([], $c->cookies);
    // origen ajeno
    $csrf = $c->csrfFrom($c->get('/admin/login'));
    $r = $c->req('POST', '/admin/login', ['csrf' => $csrf, 'email' => 'a@b.es', 'password' => PW], ['origin' => 'https://evil.example']);
    T::eq(403, $r->status);
    T::eq([], $c->cookies);
    $r = $c->req('POST', '/admin/login', ['csrf' => $csrf, 'email' => 'a@b.es', 'password' => PW], ['origin' => '', 'referer' => 'https://luxaeterna.es.evil.example/x']);
    T::eq(403, $r->status, 'Referer que solo empieza igual');
    // sesión válida: POST sin token, con token de otra sesión y con token anónimo
    [$id, $secret] = [1, null];
    $a = new Client($sv);
    $a->login('a@b.es', PW);
    $sec = $sv->users->totpSecret($sv->users->byId(1));
    $a->post('/admin/login/mfa', ['code' => nextCode($sec, 0)], '/admin/login/mfa');
    T::eq(200, $a->get('/admin/account')->status);
    T::eq(403, $a->req('POST', '/admin/account/password', ['current' => PW, 'password' => 'Otra-Larga_Clave88', 'password2' => 'Otra-Larga_Clave88'])->status, 'sin token');
    T::eq(403, $a->req('POST', '/admin/logout', ['csrf' => $csrf])->status, 'token anónimo no vale en sesión');
    T::eq(403, $a->req('POST', '/admin/logout', ['csrf' => 'abc'])->status);
    T::eq(200, $a->get('/admin/account')->status, 'la sesión sigue viva tras los intentos falsos');
    T::eq(403, $a->req('POST', '/admin/logout', ['csrf' => $a->csrfFrom($a->get('/admin/account'))], ['origin' => 'https://evil.example'])->status);
});
T::test('sesión: caduca por inactividad y por época; logout la destruye en el servidor', function () {
    [$sv] = lux_app();
    [$id] = lux_user($sv, 'a@b.es', 'admin', false);
    $sv->users->stageTotp($id, Totp::generateSecret());
    $sv->users->activateTotp($id, 0);
    $c = new Client($sv);
    $c->login('a@b.es', PW);
    $sec = $sv->users->totpSecret($sv->users->byId($id));
    $c->post('/admin/login/mfa', ['code' => nextCode($sec, 0)], '/admin/login/mfa');
    T::eq(200, $c->get('/admin/account')->status);
    $tok = $c->cookies['__Secure-lux_sid'];
    $sv->db->run('UPDATE sessions SET last_seen_at = last_seen_at - 4000');
    T::eq('/admin/login', $c->get('/admin/account')->headers['Location'], 'inactividad');
    T::eq(0, (int)$sv->db->val('SELECT COUNT(*) FROM sessions WHERE kind = "auth"'), 'se borra del servidor');
    // época: cambiar rol invalida
    $c->login('a@b.es', PW);
    $c->post('/admin/login/mfa', ['code' => nextCode($sec, 99999999)], '/admin/login/mfa');
    $sv->users->setRole($id, 'editor');
    T::eq('/admin/login', $c->get('/admin/')->headers['Location'], 'cambio de rol cierra sesiones');
    // logout
    $sv->users->setRole($id, 'admin');
    $c->login('a@b.es', PW);
    $c->post('/admin/login/mfa', ['code' => nextCode($sec, 199999999)], '/admin/login/mfa');
    $old = $c->cookies['__Secure-lux_sid'];
    $c->post('/admin/logout', [], '/admin/account');
    $c->cookies['__Secure-lux_sid'] = $old;
    T::eq('/admin/login', $c->get('/admin/')->headers['Location'], 'cookie robada tras logout no sirve');
});
T::test('sesión: se rota el identificador al iniciar sesión (fijación de sesión)', function () {
    [$sv] = lux_app();
    lux_user($sv, 'a@b.es');
    $c = new Client($sv);
    $c->cookies['__Secure-lux_sid'] = str_repeat('A', 43);
    $c->login('a@b.es', PW);
    T::ok($c->cookies['__Secure-lux_sid'] !== str_repeat('A', 43));
    $c2 = new Client($sv);
    $c2->login('a@b.es', PW);
    $first = $c2->cookies['__Secure-lux_sid'];
    $c2->login('a@b.es', PW);
    T::ok($c2->cookies['__Secure-lux_sid'] !== $first);
    T::eq(null, $sv->sessions->find($first), 'el id anterior ya no vale');
});
T::test('alta con enlace: un solo uso, caduca, valida la contraseña y cierra sesiones previas', function () {
    [$sv] = lux_app();
    $id = $sv->users->create('nuevo@b.es', 'editor');
    $tok = $sv->tokens->create($id, 'setup');
    $c = new Client($sv);
    T::eq(200, $c->get('/admin/setup', ['token' => $tok])->status);
    T::eq(400, $c->get('/admin/setup', ['token' => 'x' . substr($tok, 1)])->status);
    T::eq(400, $c->get('/admin/reset', ['token' => $tok])->status, 'otro propósito');
    $form = fn(string $p, string $p2) => $c->req('POST', '/admin/setup', ['csrf' => $c->csrfFrom($c->get('/admin/setup', ['token' => $tok])), 'token' => $tok, 'password' => $p, 'password2' => $p2]);
    T::eq(422, $form('corta', 'corta')->status);
    T::eq(422, $form(PW, PW . 'x')->status);
    T::eq(303, $form(PW, PW)->status);
    T::eq(400, $form(PW, PW)->status, 'el token ya se usó');
    T::eq('active', $sv->users->byId($id)['status']);
    T::eq(303, $c->login('nuevo@b.es', PW)->status);
    // caducado
    $tok2 = $sv->tokens->create($id, 'reset', time() - 4000);
    T::eq(400, $c->get('/admin/reset', ['token' => $tok2])->status);
});
T::test('cambio de contraseña: exige la actual, cierra las demás sesiones y mantiene la actual', function () {
    [$sv] = lux_app();
    [$id, $secret] = lux_user($sv, 'a@b.es', 'admin', true);
    $a = new Client($sv);
    $a->login('a@b.es', PW);
    $a->post('/admin/login/mfa', ['code' => nextCode($secret, 0)], '/admin/login/mfa');
    $b = new Client($sv, '198.51.100.9');
    $b->login('a@b.es', PW);
    $b->post('/admin/login/mfa', ['code' => nextCode($secret, intdiv(time(), 30))], '/admin/login/mfa');
    T::eq(200, $b->get('/admin/account')->status);
    $new = 'Otra-Larga_Clave88';
    T::eq(422, $a->post('/admin/account/password', ['current' => 'mala', 'password' => $new, 'password2' => $new], '/admin/account')->status);
    T::eq(303, $a->post('/admin/account/password', ['current' => PW, 'password' => $new, 'password2' => $new], '/admin/account')->status);
    T::eq(200, $a->get('/admin/account')->status, 'la sesión actual continúa');
    T::eq('/admin/login', $b->get('/admin/account')->headers['Location'], 'las demás se cierran');
    T::eq(401, (new Client($sv, '198.51.100.77'))->login('a@b.es', PW)->status, 'la antigua ya no vale');
});
T::test('sesiones propias: no se pueden revocar las de otra persona (IDOR)', function () {
    [$sv] = lux_app();
    [, $sa] = lux_user($sv, 'a@b.es', 'editor', true);
    [, $sb] = lux_user($sv, 'b@b.es', 'editor', true);
    $a = new Client($sv);
    $a->login('a@b.es', PW);
    $a->post('/admin/login/mfa', ['code' => nextCode($sa, 0)], '/admin/login/mfa');
    $b = new Client($sv, '198.51.100.9');
    $b->login('b@b.es', PW);
    $b->post('/admin/login/mfa', ['code' => nextCode($sb, 0)], '/admin/login/mfa');
    $prefixB = substr(\LuxAeterna\Security\Sessions::hash($b->cookies['__Secure-lux_sid']), 0, 16);
    $a->post('/admin/account/sessions/revoke', ['sid' => $prefixB], '/admin/account');
    T::eq(200, $b->get('/admin/account')->status, 'la sesión de B sigue viva');
    // y A tampoco puede cerrar la suya por esta vía (se usa logout)
    $prefixA = substr(\LuxAeterna\Security\Sessions::hash($a->cookies['__Secure-lux_sid']), 0, 16);
    $a->post('/admin/account/sessions/revoke', ['sid' => $prefixA], '/admin/account');
    T::eq(200, $a->get('/admin/account')->status);
});
T::test('autorización: un editor no ve la auditoría; un admin sí; la auditoría no contiene secretos', function () {
    [$sv] = lux_app();
    [, $se] = lux_user($sv, 'e@b.es', 'editor', true);
    [, $sa] = lux_user($sv, 'a@b.es', 'admin', true);
    $e = new Client($sv);
    $e->login('e@b.es', PW);
    $e->post('/admin/login/mfa', ['code' => nextCode($se, 0)], '/admin/login/mfa');
    T::eq(403, $e->get('/admin/audit')->status);
    T::ok(!str_contains($e->get('/admin/')->body, '/admin/audit'), 'ni siquiera enlaza');
    $a = new Client($sv, '198.51.100.9');
    $a->login('a@b.es', PW);
    $a->post('/admin/login/mfa', ['code' => nextCode($sa, 0)], '/admin/login/mfa');
    $r = $a->get('/admin/audit');
    T::eq(200, $r->status);
    T::contains('login.success', $r->body);
    foreach ([PW, $sa, 'Un-Buen'] as $secret) {
        T::ok(!str_contains($r->body, $secret));
    }
});
T::test('escapado: el correo y los datos se muestran sin ejecutar HTML', function () {
    [$sv] = lux_app();
    $c = new Client($sv);
    $r = $c->post('/admin/login', ['email' => '"><img src=x onerror=alert(1)>', 'password' => 'x']);
    T::ok(!str_contains($r->body, '<img src=x'));
    T::contains('&quot;&gt;&lt;img', $r->body);
});
T::test('proxy: la IP de X-Forwarded-For solo cuenta con proxy declarado', function () {
    [$sv] = lux_app();
    lux_user($sv, 'a@b.es');
    $c = new Client($sv, '203.0.113.7');
    for ($i = 0; $i < 20; $i++) {
        $c->req('POST', '/admin/login', ['csrf' => $c->csrfFrom($c->get('/admin/login')), 'email' => "x$i@b.es", 'password' => 'mala'], ['x-forwarded-for' => "9.9.9.$i"]);
    }
    $blocked = (int)$sv->db->val("SELECT COUNT(*) FROM audit WHERE event='login.blocked'");
    $c->req('POST', '/admin/login', ['csrf' => $c->csrfFrom($c->get('/admin/login')), 'email' => 'y@b.es', 'password' => 'mala'], ['x-forwarded-for' => '8.8.8.8']);
    T::ok((int)$sv->db->val("SELECT COUNT(*) FROM audit WHERE event='login.blocked'") > $blocked, 'falsificar la cabecera no esquiva el límite');
});
T::test('http (localhost): cookie sin prefijo __Secure- ni Secure, sin HSTS', function () {
    [$sv] = lux_app(['site' => ['base_url' => 'http://localhost:8080']]);
    lux_user($sv, 'a@b.es');
    $c = new Client($sv);
    $csrf = $c->csrfFrom($c->get('/admin/login'));
    $r = $c->req('POST', '/admin/login', ['csrf' => $csrf, 'email' => 'a@b.es', 'password' => PW], ['origin' => 'http://localhost:8080']);
    T::contains('lux_sid=', $r->cookies[0]);
    T::ok(!str_contains($r->cookies[0], '__Secure-') && !str_contains($r->cookies[0], '; Secure'));
    T::ok(!isset($r->headers['Strict-Transport-Security']));
});
T::test('petición: la query se toma de REQUEST_URI aunque $_GET llegue vacío', function () {
    $bak = [$_SERVER, $_GET];
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/setup?token=abc_DEF-123&x[]=1', 'REMOTE_ADDR' => '1.2.3.4'];
    $_GET = [];
    $r = Request::fromGlobals();
    [$_SERVER, $_GET] = $bak;
    T::eq('/admin/setup', $r->path);
    T::eq('abc_DEF-123', $r->query['token'] ?? null);
    T::ok(!isset($r->query['x']), 'los arrays no cuentan como cadenas');
});
