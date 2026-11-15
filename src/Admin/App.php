<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

use LuxAeterna\Security\Authorizer;
use LuxAeterna\Security\ClientIp;
use LuxAeterna\Security\Password;
use LuxAeterna\Security\Sessions;
use LuxAeterna\Security\Totp;

/** Controlador frontal del panel. handle() es puro respecto a PHP global: facilita las pruebas. */
final class App
{
    private const LOGIN_IP = [20, 900];
    private const LOGIN_ACCOUNT = [5, 900];
    private const MFA_USER = [5, 900];
    private const TOKEN_IP = [20, 900];

    private string $prefix;
    private bool $https;
    private string $origin;
    private string $ip = '';
    private string $ua = '';
    private ?string $token = null;
    /** @var array{session:array<string,mixed>,user:array<string,mixed>}|null */
    private ?array $auth = null;

    public function __construct(private readonly Services $sv)
    {
        $this->prefix = rtrim((string)$sv->config->get('admin.prefix', '/admin'), '/');
        $base = $sv->config->baseUrl();
        $this->https = str_starts_with($base, 'https://');
        $this->origin = (string)parse_url($base, PHP_URL_SCHEME) . '://' . (string)parse_url($base, PHP_URL_HOST)
            . (($port = parse_url($base, PHP_URL_PORT)) ? ':' . $port : '');
    }

    public function handle(Request $r): Response
    {
        $this->ip = ClientIp::resolve($r->remoteAddr, $r->header('x-forwarded-for') ?: null, $this->sv->trustedProxies());
        $this->ua = $r->header('user-agent');
        $this->token = $r->cookies[$this->cookieName()] ?? null;
        $this->auth = $this->token !== null ? $this->sv->sessions->find($this->token) : null;
        try {
            $res = $this->route($r);
        } catch (\Throwable $e) {
            $op = \LuxAeterna\Core\Operation::id();
            (new \LuxAeterna\Core\Logger($this->sv->config->path('data') . '/logs'))->error('error en el panel', ['type' => get_class($e), 'msg' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            $res = new Response(500, View::page('Error', '<h1>Algo ha fallado</h1><p>Referencia de la operación: <code>' . View::h($op) . '</code></p>', $this->ctx()));
        }
        return $this->secure($res);
    }

    private function route(Request $r): Response
    {
        $path = $r->path;
        if ($path === $this->prefix) {
            return Response::redirect($this->prefix . '/', 301);
        }
        if (!str_starts_with($path, $this->prefix . '/')) {
            return $this->notFound();
        }
        $p = substr($path, strlen($this->prefix));
        $p = $p === '/' ? $p : rtrim($p, '/');
        $post = $r->method === 'POST';
        if (!in_array($r->method, ['GET', 'HEAD', 'POST'], true)) {
            return new Response(405, 'Método no permitido', ['Allow' => 'GET, POST']);
        }
        if ($post && !$this->sameOrigin($r)) {
            return new Response(403, View::page('Prohibido', '<h1>Solicitud no permitida</h1><p>El origen de la petición no coincide.</p>', $this->ctx()));
        }

        // Rutas sin sesión completa
        switch ($p) {
            case '/login':
                return $post ? $this->loginPost($r) : $this->loginForm();
            case '/login/mfa':
                return $post ? $this->mfaPost($r) : $this->mfaForm();
            case '/setup':
            case '/reset':
                return $this->tokenFlow($r, $p === '/setup' ? 'setup' : 'reset');
        }

        // A partir de aquí se exige sesión completa
        $user = $this->auth !== null && $this->auth['session']['kind'] === 'auth' ? $this->auth['user'] : null;
        if ($user === null || !Authorizer::can($user, 'panel.access')) {
            return Response::redirect($this->prefix . '/login');
        }
        if ($post && !$this->sv->csrf->verify($r->input('csrf'), $this->sid())) {
            return new Response(403, View::page('Prohibido', '<h1>Solicitud no válida</h1><p>El formulario caducó o no es legítimo. Vuelve atrás y reinténtalo.</p>', $this->ctx()));
        }
        if ($post && $p === '/logout') {
            $this->sv->audit->log('logout', (int)$user['id'], (string)$user['email'], null, [], $this->ip);
            $this->sv->sessions->destroy((string)$this->token);
            $res = Response::redirect($this->prefix . '/login');
            $res->cookies[] = $this->cookie('', 0);
            return $res;
        }
        // MFA obligatorio: hasta configurarlo solo se permite configurarlo
        if ($this->sv->mfaRequired() && !(int)$user['totp_enabled'] && !str_starts_with($p, '/account/mfa')) {
            return Response::redirect($this->prefix . '/account/mfa');
        }
        switch ($p) {
            case '/':
                return $this->dashboard($user);
            case '/account':
                return $this->account($user);
            case '/account/password':
                return $post ? $this->passwordPost($r, $user) : Response::redirect($this->prefix . '/account');
            case '/account/sessions/revoke':
                return $post ? $this->revokePost($r, $user) : Response::redirect($this->prefix . '/account');
            case '/account/mfa':
                return $post ? $this->mfaEnrollPost($r, $user) : $this->mfaEnrollForm($user);
            case '/account/mfa/recovery':
                return $post ? $this->recoveryPost($r, $user) : Response::redirect($this->prefix . '/account');
            case '/audit':
                if (!Authorizer::can($user, 'audit.read')) {
                    return new Response(403, View::page('Prohibido', '<h1>Sin permiso</h1>', $this->ctx($user)));
                }
                return $this->audit($user);
        }
        $ip = $this->ip;
        $ctx = fn(?array $u, ?string $e, ?string $f): array => $this->ctx($u, $e, $f);
        if (preg_match('#^/(content|preview)(/|$)#', $p)) {
            $res = (new ContentController($this->sv, $this->prefix, $ctx, $ip))->handle($r, $user, $p, $this->sid());
        } elseif (str_starts_with($p, '/media')) {
            $res = (new MediaController($this->sv, $this->prefix, $ctx, $ip))->handle($r, $user, $p);
        } elseif (str_starts_with($p, '/design')) {
            $res = (new DesignController($this->sv, $this->prefix, $ctx, $ip))->handle($r, $user, $p);
        } else {
            $res = (new SiteController($this->sv, $this->prefix, $ctx, $ip))->handle($r, $user, $p);
        }
        return $res ?? $this->notFound($user);
    }

    /* ---------- login ---------- */

    private function loginForm(?string $error = null, string $email = ''): Response
    {
        $form = '<h1>Acceso</h1><form method="post" action="' . $this->prefix . '/login" autocomplete="on">' . View::csrf($this->sv->csrf->token('anon'))
            . '<label>Correo<input type="email" name="email" value="' . View::h($email) . '" required autocomplete="username" maxlength="190"></label>'
            . '<label>Contraseña<input type="password" name="password" required autocomplete="current-password" maxlength="256"></label>'
            . '<button>Entrar</button></form>';
        return new Response($error !== null ? 401 : 200, View::page('Acceso', $form, $this->ctx(null, $error)));
    }

    private function loginPost(Request $r): Response
    {
        $generic = 'Correo o contraseña incorrectos, o demasiados intentos. Inténtalo de nuevo más tarde.';
        if (!$this->sv->csrf->verify($r->input('csrf'), 'anon')) {
            return $this->loginForm('El formulario caducó. Vuelve a intentarlo.');
        }
        $email = trim($r->input('email'));
        $pass = $r->input('password');
        [$ipLimit, $win] = self::LOGIN_IP;
        [$accLimit] = self::LOGIN_ACCOUNT;
        if ($this->sv->throttle->blocked('login_ip', $this->ip, $ipLimit, $win) || $this->sv->throttle->blocked('login_acc', $email, $accLimit, $win)) {
            Password::dummyVerify($pass);
            $this->sv->audit->log('login.blocked', null, mb_substr($email, 0, 190), null, [], $this->ip);
            return $this->loginForm($generic, $email);
        }
        $u = $email !== '' ? $this->sv->users->byEmail($email) : null;
        $ok = $u !== null && $u['status'] === 'active' && Password::verify($pass, $u['password_hash'] ?? null);
        if (!$ok) {
            if ($u === null || $u['status'] !== 'active') {
                Password::dummyVerify($pass);
            }
            $this->sv->throttle->fail('login_ip', $this->ip, $win);
            $this->sv->throttle->fail('login_acc', $email, $win);
            $this->sv->audit->log('login.failure', $u !== null ? (int)$u['id'] : null, mb_substr($email, 0, 190), null, [], $this->ip);
            return $this->loginForm($generic, $email);
        }
        $this->sv->users->rehashIfNeeded((int)$u['id'], $pass, $u['password_hash']);
        $this->sv->throttle->clear('login_acc', $email);
        if ($this->token !== null) {
            $this->sv->sessions->destroy($this->token);
        }
        $kind = (int)$u['totp_enabled'] ? 'pending_mfa' : 'auth';
        $tok = $this->sv->sessions->create((int)$u['id'], $kind, (int)$u['session_epoch'], $this->ip, $this->ua);
        if ($kind === 'auth') {
            $this->sv->users->recordLogin((int)$u['id']);
            $this->sv->audit->log('login.success', (int)$u['id'], (string)$u['email'], null, ['mfa' => 'none'], $this->ip);
        }
        $res = Response::redirect($this->prefix . ($kind === 'auth' ? '/' : '/login/mfa'));
        $res->cookies[] = $this->cookie($tok, $kind === 'auth' ? 0 : Sessions::PENDING_TTL);
        return $res;
    }

    private function mfaForm(?string $error = null): Response
    {
        if ($this->auth === null || $this->auth['session']['kind'] !== 'pending_mfa') {
            return Response::redirect($this->prefix . '/login');
        }
        $form = '<h1>Verificación en dos pasos</h1><p>Introduce el código de 6 cifras de tu aplicación de autenticación, o un código de recuperación.</p>'
            . '<form method="post" action="' . $this->prefix . '/login/mfa">' . View::csrf($this->sv->csrf->token($this->sid()))
            . '<label>Código<input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="20" autofocus></label><button>Verificar</button></form>';
        return new Response($error !== null ? 401 : 200, View::page('Verificación', $form, $this->ctx(null, $error)));
    }

    private function mfaPost(Request $r): Response
    {
        if ($this->auth === null || $this->auth['session']['kind'] !== 'pending_mfa') {
            return Response::redirect($this->prefix . '/login');
        }
        if (!$this->sv->csrf->verify($r->input('csrf'), $this->sid())) {
            return $this->mfaForm('El formulario caducó. Vuelve a intentarlo.');
        }
        $u = $this->auth['user'];
        $uid = (string)$u['id'];
        [$limit, $win] = self::MFA_USER;
        if ($this->sv->throttle->blocked('mfa_user', $uid, $limit, $win) || $this->sv->throttle->blocked('login_ip', $this->ip, self::LOGIN_IP[0] * 2, $win)) {
            $this->sv->audit->log('mfa.blocked', (int)$u['id'], (string)$u['email'], null, [], $this->ip);
            return $this->mfaForm('Demasiados intentos. Espera unos minutos y vuelve a entrar.');
        }
        $code = $r->input('code');
        $method = null;
        $secret = $this->sv->users->totpSecret($u);
        $step = $secret !== null ? Totp::verify($secret, $code, (int)$u['totp_last_step']) : null;
        if ($step !== null && $this->sv->users->markTotpStep((int)$u['id'], $step)) {
            $method = 'totp';
        } elseif ($this->sv->users->useRecoveryCode((int)$u['id'], $code)) {
            $method = 'recovery';
        }
        if ($method === null) {
            $this->sv->throttle->fail('mfa_user', $uid, $win);
            $this->sv->throttle->fail('login_ip', $this->ip, $win);
            $this->sv->audit->log('mfa.failure', (int)$u['id'], (string)$u['email'], null, [], $this->ip);
            return $this->mfaForm('Código incorrecto.');
        }
        $this->sv->throttle->clear('mfa_user', $uid);
        $tok = $this->sv->sessions->rotate((string)$this->token, 'auth', (int)$u['session_epoch'], $this->ip, $this->ua);
        $this->sv->users->recordLogin((int)$u['id']);
        $this->sv->audit->log('login.success', (int)$u['id'], (string)$u['email'], null, ['mfa' => $method], $this->ip);
        $res = Response::redirect($this->prefix . '/');
        $res->cookies[] = $this->cookie($tok, 0);
        return $res;
    }

    /* ---------- alta y recuperación con token de un solo uso ---------- */

    private function tokenFlow(Request $r, string $purpose): Response
    {
        $tok = $r->method === 'POST' ? $r->input('token') : ($r->query['token'] ?? '');
        $title = $purpose === 'setup' ? 'Crear contraseña' : 'Restablecer contraseña';
        $bad = new Response(400, View::page($title, '<h1>Enlace no válido</h1><p>El enlace ha caducado o ya se usó. Pide uno nuevo al administrador del sitio.</p>', $this->ctx()));
        [$limit, $win] = self::TOKEN_IP;
        if ($this->sv->throttle->blocked('token_ip', $this->ip, $limit, $win)) {
            return $bad;
        }
        $row = $this->sv->tokens->peek($tok, $purpose);
        if ($row === null) {
            $this->sv->throttle->fail('token_ip', $this->ip, $win);
            return $bad;
        }
        $form = function (?string $err = null) use ($tok, $purpose, $title, $row): Response {
            $f = '<h1>' . View::h($title) . '</h1><p>Cuenta: <strong>' . View::h((string)$row['email']) . '</strong></p>'
                . '<form method="post" action="' . $this->prefix . '/' . $purpose . '">' . View::csrf($this->sv->csrf->token('anon'))
                . '<input type="hidden" name="token" value="' . View::h($tok) . '">'
                . '<label>Contraseña nueva (mínimo ' . Password::MIN_LENGTH . ' caracteres)<input type="password" name="password" required minlength="' . Password::MIN_LENGTH . '" maxlength="' . Password::MAX_LENGTH . '" autocomplete="new-password"></label>'
                . '<label>Repite la contraseña<input type="password" name="password2" required autocomplete="new-password"></label><button>Guardar</button></form>';
            return new Response($err !== null ? 422 : 200, View::page($title, $f, $this->ctx(null, $err)));
        };
        if ($r->method !== 'POST') {
            return $form();
        }
        if (!$this->sv->csrf->verify($r->input('csrf'), 'anon')) {
            return $form('El formulario caducó. Vuelve a intentarlo.');
        }
        $err = Password::validate($r->input('password'), (string)$row['email']);
        if ($err === null && !hash_equals($r->input('password'), $r->input('password2'))) {
            $err = 'Las contraseñas no coinciden.';
        }
        if ($err !== null) {
            return $form($err);
        }
        if (!$this->sv->tokens->consume((int)$row['token_id'])) {
            return $bad;
        }
        $this->sv->users->setPassword((int)$row['id'], $r->input('password'));
        $this->sv->sessions->destroyAllForUser((int)$row['id']);
        $this->sv->audit->log($purpose === 'setup' ? 'setup.complete' : 'password.reset', (int)$row['id'], (string)$row['email'], null, [], $this->ip);
        $res = Response::redirect($this->prefix . '/login');
        $res->cookies[] = $this->cookie('', 0);
        return $res;
    }

    /* ---------- áreas autenticadas ---------- */

    /** @param array<string,mixed> $user */
    private function dashboard(array $user): Response
    {
        $body = '<h1>Hola' . ($user['name'] !== '' ? ', ' . View::h((string)$user['name']) : '') . '</h1>'
            . '<p>Has entrado como <strong>' . View::h((string)$user['email']) . '</strong> (' . View::h((string)$user['role']) . ').</p>'
            . '<ul class="menu"><li><a href="' . $this->prefix . '/content">Contenido (páginas y entradas)</a></li>'
            . (Authorizer::can($user, 'media.read') ? '<li><a href="' . $this->prefix . '/media">Biblioteca multimedia (imágenes)</a></li>' : '')
            . '<li><a href="' . $this->prefix . '/terms">Categorías y etiquetas</a></li>'
            . (Authorizer::can($user, 'site.publish') ? '<li><a href="' . $this->prefix . '/publish">Publicaciones, versiones y reversión</a></li>' : '')
            . (Authorizer::can($user, 'site.manage') ? '<li><a href="' . $this->prefix . '/menus">Menús</a></li><li><a href="' . $this->prefix . '/design">Diseño (cabecera, colores, tipografía, logo)</a></li><li><a href="' . $this->prefix . '/settings">Ajustes y generación del sitio</a></li>' : '')
            . '</ul>';
        return new Response(200, View::page('Inicio', $body, $this->ctx($user)));
    }

    /** @param array<string,mixed> $user */
    private function account(array $user, ?string $flash = null, ?string $error = null): Response
    {
        $csrf = View::csrf($this->sv->csrf->token($this->sid()));
        $me = $this->sid();
        $rows = '';
        foreach ($this->sv->sessions->listForUser((int)$user['id']) as $s) {
            $isMe = $s['id_hash'] === $me;
            $rows .= '<li>' . View::h((string)$s['ua_label']) . ' · visto ' . date('Y-m-d H:i', (int)$s['last_seen_at'])
                . ($isMe ? ' <em>(esta sesión)</em>' : '<form method="post" action="' . $this->prefix . '/account/sessions/revoke" class="inline">' . $csrf
                    . '<input type="hidden" name="sid" value="' . View::h(substr((string)$s['id_hash'], 0, 16)) . '"><button class="link">Cerrar</button></form>') . '</li>';
        }
        $mfa = (int)$user['totp_enabled']
            ? '<p>Segundo factor: <strong>activo</strong>. Códigos de recuperación sin usar: ' . $this->sv->users->remainingRecoveryCodes((int)$user['id']) . '.</p>'
              . '<form method="post" action="' . $this->prefix . '/account/mfa/recovery">' . $csrf . '<label>Contraseña actual<input type="password" name="current" required autocomplete="current-password"></label><button>Generar códigos de recuperación nuevos</button></form>'
            : '<p>Segundo factor: <strong>no configurado</strong>. <a href="' . $this->prefix . '/account/mfa">Configurarlo ahora</a>.</p>';
        $body = '<h1>Mi cuenta</h1><h2>Contraseña</h2><form method="post" action="' . $this->prefix . '/account/password">' . $csrf
            . '<label>Contraseña actual<input type="password" name="current" required autocomplete="current-password"></label>'
            . '<label>Contraseña nueva<input type="password" name="password" required minlength="' . Password::MIN_LENGTH . '" autocomplete="new-password"></label>'
            . '<label>Repite la nueva<input type="password" name="password2" required autocomplete="new-password"></label><button>Cambiar contraseña</button></form>'
            . '<h2>Segundo factor</h2>' . $mfa . '<h2>Sesiones abiertas</h2><ul class="sessions">' . $rows . '</ul>';
        return new Response($error !== null ? 422 : 200, View::page('Mi cuenta', $body, $this->ctx($user, $error, $flash)));
    }

    /** @param array<string,mixed> $user */
    private function passwordPost(Request $r, array $user): Response
    {
        $uid = (string)$user['id'];
        if ($this->sv->throttle->blocked('pwchange', $uid, 5, 900)) {
            return $this->account($user, null, 'Demasiados intentos. Espera unos minutos.');
        }
        if (!Password::verify($r->input('current'), $user['password_hash'] ?? null)) {
            $this->sv->throttle->fail('pwchange', $uid, 900);
            return $this->account($user, null, 'La contraseña actual no es correcta.');
        }
        $err = Password::validate($r->input('password'), (string)$user['email']);
        if ($err === null && !hash_equals($r->input('password'), $r->input('password2'))) {
            $err = 'Las contraseñas no coinciden.';
        }
        if ($err !== null) {
            return $this->account($user, null, $err);
        }
        $this->sv->users->setPassword((int)$user['id'], $r->input('password'));
        $this->sv->sessions->destroyAllForUser((int)$user['id']);
        $this->sv->audit->log('password.change', (int)$user['id'], (string)$user['email'], null, [], $this->ip);
        $fresh = $this->sv->users->byId((int)$user['id']);
        $tok = $this->sv->sessions->create((int)$user['id'], 'auth', (int)$fresh['session_epoch'], $this->ip, $this->ua);
        $res = Response::redirect($this->prefix . '/account');
        $res->cookies[] = $this->cookie($tok, 0);
        return $res;
    }

    /** @param array<string,mixed> $user */
    private function revokePost(Request $r, array $user): Response
    {
        $prefix = $r->input('sid');
        if ($prefix !== substr($this->sid(), 0, 16) && $this->sv->sessions->revokeByPrefix($prefix, (int)$user['id'])) {
            $this->sv->audit->log('session.revoke', (int)$user['id'], (string)$user['email'], null, [], $this->ip);
        }
        return Response::redirect($this->prefix . '/account');
    }

    /** @param array<string,mixed> $user */
    private function mfaEnrollForm(array $user, ?string $error = null): Response
    {
        if ((int)$user['totp_enabled']) {
            return Response::redirect($this->prefix . '/account');
        }
        $secret = $this->sv->users->totpSecret($user);
        if ($secret === null) {
            $secret = Totp::generateSecret();
            $this->sv->users->stageTotp((int)$user['id'], $secret);
        }
        $uri = Totp::uri('LuxAeterna', (string)$user['email'], $secret);
        $body = '<h1>Configurar el segundo factor</h1><p>En tu aplicación de autenticación (Google Authenticator, Aegis, 1Password…) añade una cuenta con esta clave:</p>'
            . '<p class="secret"><code>' . View::h(trim(chunk_split($secret, 4, ' '))) . '</code></p>'
            . '<p>Desde este mismo móvil puedes abrir directamente: <a href="' . View::h($uri) . '">añadir a mi aplicación</a>.</p>'
            . '<form method="post" action="' . $this->prefix . '/account/mfa">' . View::csrf($this->sv->csrf->token($this->sid()))
            . '<label>Código de 6 cifras que muestra la aplicación<input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="10"></label><button>Activar</button></form>';
        return new Response($error !== null ? 422 : 200, View::page('Segundo factor', $body, $this->ctx($user, $error)));
    }

    /** @param array<string,mixed> $user */
    private function mfaEnrollPost(Request $r, array $user): Response
    {
        if ((int)$user['totp_enabled']) {
            return Response::redirect($this->prefix . '/account');
        }
        if ($this->sv->throttle->blocked('mfa_user', (string)$user['id'], self::MFA_USER[0], self::MFA_USER[1])) {
            return $this->mfaEnrollForm($user, 'Demasiados intentos. Espera unos minutos.');
        }
        $secret = $this->sv->users->totpSecret($user);
        $step = $secret !== null ? Totp::verify($secret, $r->input('code'), (int)$user['totp_last_step']) : null;
        if ($step === null) {
            $this->sv->throttle->fail('mfa_user', (string)$user['id'], self::MFA_USER[1]);
            return $this->mfaEnrollForm($user, 'Código incorrecto. Comprueba la hora de tu móvil e inténtalo de nuevo.');
        }
        $this->sv->users->activateTotp((int)$user['id'], $step);
        $codes = $this->sv->users->generateRecoveryCodes((int)$user['id']);
        $this->sv->audit->log('mfa.enabled', (int)$user['id'], (string)$user['email'], null, [], $this->ip);
        $tok = $this->sv->sessions->rotate((string)$this->token, 'auth', (int)$user['session_epoch'], $this->ip, $this->ua);
        $res = $this->recoveryPage($user, $codes);
        $res->cookies[] = $this->cookie($tok, 0);
        return $res;
    }

    /** @param array<string,mixed> $user @param list<string> $codes */
    private function recoveryPage(array $user, array $codes): Response
    {
        $body = '<h1>Códigos de recuperación</h1><p><strong>Guárdalos ahora</strong> en un lugar seguro (gestor de contraseñas). No se volverán a mostrar. Cada uno sirve una sola vez si pierdes el móvil.</p><ul class="codes">'
            . implode('', array_map(static fn(string $c): string => '<li><code>' . View::h($c) . '</code></li>', $codes))
            . '</ul><p><a href="' . $this->prefix . '/">Continuar</a></p>';
        return new Response(200, View::page('Códigos de recuperación', $body, $this->ctx($user)));
    }

    /** @param array<string,mixed> $user */
    private function recoveryPost(Request $r, array $user): Response
    {
        if (!(int)$user['totp_enabled']) {
            return Response::redirect($this->prefix . '/account/mfa');
        }
        if ($this->sv->throttle->blocked('pwchange', (string)$user['id'], 5, 900) || !Password::verify($r->input('current'), $user['password_hash'] ?? null)) {
            $this->sv->throttle->fail('pwchange', (string)$user['id'], 900);
            return $this->account($user, null, 'La contraseña actual no es correcta.');
        }
        $codes = $this->sv->users->generateRecoveryCodes((int)$user['id']);
        $this->sv->audit->log('mfa.recovery_regenerated', (int)$user['id'], (string)$user['email'], null, [], $this->ip);
        return $this->recoveryPage($user, $codes);
    }

    /** @param array<string,mixed> $user */
    private function audit(array $user): Response
    {
        $rows = '';
        foreach ($this->sv->audit->recent(100) as $a) {
            $rows .= '<tr><td>' . date('Y-m-d H:i:s', (int)$a['ts']) . '</td><td>' . View::h((string)$a['event']) . '</td><td>' . View::h((string)$a['actor_label']) . '</td><td>' . View::h((string)$a['ip']) . '</td></tr>';
        }
        $body = '<h1>Auditoría</h1><div class="scroll"><table><thead><tr><th>Fecha</th><th>Evento</th><th>Quién</th><th>IP</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        return new Response(200, View::page('Auditoría', $body, $this->ctx($user)));
    }

    /* ---------- utilidades ---------- */

    /** @param array<string,mixed>|null $user */
    private function ctx(?array $user = null, ?string $error = null, ?string $flash = null): array
    {
        $ctx = ['prefix' => $this->prefix];
        if ($user !== null) {
            $ctx += ['user' => $user, 'csrf' => $this->sv->csrf->token($this->sid()), 'canAudit' => Authorizer::can($user, 'audit.read')];
        }
        if ($error !== null) {
            $ctx['error'] = $error;
        }
        if ($flash !== null) {
            $ctx['flash'] = $flash;
        }
        return $ctx;
    }

    /** @param array<string,mixed>|null $user */
    private function notFound(?array $user = null): Response
    {
        return new Response(404, View::page('No encontrado', '<h1>No encontrado</h1>', $this->ctx($user)));
    }

    private function sid(): string
    {
        return $this->token !== null ? Sessions::hash($this->token) : 'anon';
    }

    private function cookieName(): string
    {
        return ($this->https ? '__Secure-' : '') . 'lux_sid';
    }

    private function cookie(string $value, int $maxAge): string
    {
        $c = $this->cookieName() . '=' . $value . '; Path=' . $this->prefix . '; HttpOnly; SameSite=Strict';
        if ($value === '') {
            $c .= '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        } elseif ($maxAge > 0) {
            $c .= '; Max-Age=' . $maxAge;
        }
        return $this->https ? $c . '; Secure' : $c;
    }

    private function sameOrigin(Request $r): bool
    {
        $origin = $r->header('origin');
        if ($origin !== '') {
            return hash_equals($this->origin, $origin);
        }
        $ref = $r->header('referer');
        return $ref === '' || $ref === $this->origin || str_starts_with($ref, $this->origin . '/');
    }

    private function secure(Response $res): Response
    {
        $res->headers += [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
        if ($this->https) {
            $res->headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $res;
    }
}
