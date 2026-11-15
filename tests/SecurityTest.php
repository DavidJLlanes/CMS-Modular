<?php
declare(strict_types=1);

use LuxAeterna\Core\Database;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Core\Migrator;
use LuxAeterna\Security\Audit;
use LuxAeterna\Security\Authorizer;
use LuxAeterna\Security\ClientIp;
use LuxAeterna\Security\Csrf;
use LuxAeterna\Security\Password;
use LuxAeterna\Security\Secrets;
use LuxAeterna\Security\Sessions;
use LuxAeterna\Security\Throttle;
use LuxAeterna\Security\Tokens;
use LuxAeterna\Security\Totp;
use LuxAeterna\Security\Users;

/** @return array{0:Database,1:Secrets,2:string} */
function lux_env(): array
{
    $d = T::tmpDir();
    $db = new Database("$d/db.sqlite");
    (new Migrator($db, dirname(__DIR__) . '/migrations'))->migrate();
    return [$db, new Secrets("$d/app.key"), $d];
}

T::test('migraciones: se aplican una vez y quedan registradas', function () {
    $d = T::tmpDir();
    $db = new Database("$d/x.sqlite");
    $m = new Migrator($db, dirname(__DIR__) . '/migrations');
    T::ok(!$m->isCurrent());
    T::eq(['001_security', '002_content', '003_publishing', '004_media', '005_galleries'], $m->migrate());
    T::eq([], $m->migrate());
    T::ok($m->isCurrent());
    T::eq('0640', substr(sprintf('%o', fileperms("$d/x.sqlite")), -4));
});
T::test('secretos: cifrado de ida y vuelta, manipulación detectada, subclaves distintas', function () {
    [, $s] = lux_env();
    $blob = $s->encrypt('totp', 'JBSWY3DPEHPK3PXP');
    T::eq('JBSWY3DPEHPK3PXP', $s->decrypt('totp', $blob));
    T::eq(null, $s->decrypt('otro', $blob), 'otra subclave no descifra');
    T::eq(null, $s->decrypt('totp', substr($blob, 0, -3) . 'AAA'), 'datos alterados');
    T::ok($s->derive('a') !== $s->derive('b'));
});
T::test('totp: vectores del RFC 6238 y verificación con ventana y sin reutilización', function () {
    $s = Totp::base32Encode('12345678901234567890');
    T::eq('94287082', Totp::code($s, intdiv(59, 30), 8));
    T::eq('07081804', Totp::code($s, intdiv(1111111109, 30), 8));
    T::eq('14050471', Totp::code($s, intdiv(1111111111, 30), 8));
    $now = 1700000000;
    $code = Totp::code($s, intdiv($now, 30));
    $step = Totp::verify($s, $code, 0, $now);
    T::eq(intdiv($now, 30), $step);
    T::eq(null, Totp::verify($s, $code, $step, $now), 'el mismo código no se acepta dos veces');
    T::ok(Totp::verify($s, Totp::code($s, intdiv($now, 30) - 1), 0, $now) !== null, 'ventana -1');
    T::eq(null, Totp::verify($s, Totp::code($s, intdiv($now, 30) + 3), 0, $now), 'fuera de ventana');
    T::eq(null, Totp::verify($s, '12345', 0, $now));
    T::eq(null, Totp::verify($s, 'abcdef', 0, $now));
    T::eq(null, Totp::verify('!!!', '123456', 0, $now));
    T::eq($s, Totp::base32Encode(Totp::base32Decode($s)));
    T::ok(str_starts_with(Totp::uri('LuxAeterna', 'a@b.es', $s), 'otpauth://totp/LuxAeterna%3Aa%40b.es?secret='));
});
T::test('contraseñas: política, hash y verificación', function () {
    T::ok(Password::validate('corta') !== null);
    T::ok(Password::validate(str_repeat('a', 130)) !== null);
    T::ok(Password::validate('aaaaaaaaaaaaaaa') !== null, 'repetidos');
    T::ok(Password::validate('david.jimenez-2026', 'david.jimenez@x.es') !== null, 'contiene el correo');
    T::eq(null, Password::validate('Un-Buen_Pasaje.Largo9'));
    $h = Password::hash('Un-Buen_Pasaje.Largo9');
    T::ok(Password::verify('Un-Buen_Pasaje.Largo9', $h));
    T::ok(!Password::verify('otra', $h));
    T::ok(!Password::verify('x', null));
    T::ok(str_starts_with($h, '$argon2id$') || str_starts_with($h, '$2y$'));
});
T::test('csrf: válido, caducado, otro contexto, manipulado', function () {
    [, $s] = lux_env();
    $c = new Csrf($s, 100);
    $t = $c->token('sesionA', 1000);
    T::ok($c->verify($t, 'sesionA', 1050));
    T::ok(!$c->verify($t, 'sesionA', 1101), 'caducado');
    T::ok(!$c->verify($t, 'sesionB', 1050), 'otra sesión');
    T::ok(!$c->verify($t . 'x', 'sesionA', 1050));
    T::ok(!$c->verify('', 'sesionA', 1050));
    T::ok(!$c->verify('1.2', 'sesionA', 0));
    [$exp, $n, $sig] = explode('.', $t);
    T::ok(!$c->verify(($exp + 99999) . ".$n.$sig", 'sesionA', 1050), 'expiración alterada');
});
T::test('ip del cliente: X-Forwarded-For solo con proxy de confianza', function () {
    T::eq('9.9.9.9', ClientIp::resolve('9.9.9.9', '1.1.1.1', []), 'sin proxies declarados se ignora la cabecera');
    T::eq('9.9.9.9', ClientIp::resolve('9.9.9.9', '1.1.1.1', ['10.0.0.0/8']), 'conexión no confiable');
    T::eq('1.1.1.1', ClientIp::resolve('10.0.0.5', '1.1.1.1', ['10.0.0.0/8']));
    T::eq('1.1.1.1', ClientIp::resolve('10.0.0.5', '6.6.6.6, 1.1.1.1', ['10.0.0.0/8']), 'toma el último salto no confiable, no el falsificado del inicio');
    T::eq('10.0.0.5', ClientIp::resolve('10.0.0.5', 'basura', ['10.0.0.0/8']));
    T::ok(ClientIp::inList('2001:db8::1', ['2001:db8::/32']));
    T::ok(!ClientIp::inList('2001:db9::1', ['2001:db8::/32']));
    T::ok(ClientIp::inList('192.168.1.77', ['192.168.1.64/26']));
    T::ok(!ClientIp::inList('192.168.1.130', ['192.168.1.64/26']));
});
T::test('límite de intentos: bloquea al superar el límite, se reinicia con la ventana y al limpiar', function () {
    [$db, $s] = lux_env();
    $t = new Throttle($db, $s);
    for ($i = 0; $i < 3; $i++) {
        T::ok(!$t->blocked('login', 'a@b.es', 3, 60, 1000));
        $t->fail('login', 'a@b.es', 60, 1000 + $i);
    }
    T::ok($t->blocked('login', 'A@B.es', 3, 60, 1010), 'no distingue mayúsculas');
    T::ok(!$t->blocked('login', 'otro@b.es', 3, 60, 1010));
    T::ok(!$t->blocked('login', 'a@b.es', 3, 60, 1100), 'ventana vencida');
    $t->clear('login', 'a@b.es');
    T::ok(!$t->blocked('login', 'a@b.es', 3, 60, 1010));
    T::ok(!str_contains((string)$db->val('SELECT group_concat(key_hash) FROM throttle'), 'b.es'), 'no guarda la clave en claro');
});
T::test('sesiones: ciclo de vida, caducidad por inactividad y absoluta, rotación, época', function () {
    [$db, $s] = lux_env();
    $u = new Users($db, $s);
    $id = $u->create('a@b.es', 'admin');
    $u->setPassword($id, 'Un-Buen_Pasaje.Largo9');
    $epoch = (int)$u->byId($id)['session_epoch'];
    $ss = new Sessions($db, $s, 100, 1000);
    $tok = $ss->create($id, 'auth', $epoch, '1.2.3.4', 'Mozilla/5.0 (iPhone) Safari', 5000);
    T::eq(43, strlen($tok));
    T::eq(null, $db->one('SELECT id_hash FROM sessions WHERE id_hash = ?', [$tok]), 'el token no se guarda en claro');
    T::ok($ss->find($tok, 5050) !== null);
    T::eq(null, $ss->find($tok, 5200), 'inactividad (100 s desde la última vez visto)');
    $tok = $ss->create($id, 'auth', $epoch, '1.2.3.4', 'x', 5000);
    for ($t = 5050; $t < 5990; $t += 50) {
        T::ok($ss->find($tok, $t) !== null, "activa en $t");
    }
    T::eq(null, $ss->find($tok, 6000), 'caducidad absoluta aunque haya actividad');
    $tok = $ss->create($id, 'auth', $epoch, '1.2.3.4', 'x', 7000);
    $new = $ss->rotate($tok, 'auth', $epoch, '1.2.3.4', 'x', 7010);
    T::ok($new !== $tok);
    T::eq(null, $ss->find($tok, 7020), 'el token antiguo deja de valer');
    T::ok($ss->find($new, 7020) !== null);
    $u->bumpEpoch($id);
    T::eq(null, $ss->find($new, 7030), 'subir la época invalida las sesiones');
    T::eq(null, $ss->find('no-valido', 1));
    T::eq(null, $ss->find(str_repeat('A', 43), 1));
    $p = $ss->create($id, 'pending_mfa', (int)$u->byId($id)['session_epoch'], 'ip', 'x', 8000);
    T::eq(null, $ss->find($p, 8000 + Sessions::PENDING_TTL + 1), 'la sesión pendiente de MFA caduca pronto');
    $u->setStatus($id, 'disabled');
    T::eq(null, $ss->find($ss->create($id, 'auth', (int)$u->byId($id)['session_epoch'], 'ip', 'x', 9000), 9001), 'cuenta desactivada');
});
T::test('sesiones: revocar solo las propias (sin IDOR)', function () {
    [$db, $s] = lux_env();
    $u = new Users($db, $s);
    $a = $u->create('a@b.es', 'editor');
    $b = $u->create('b@b.es', 'editor');
    foreach ([$a, $b] as $id) {
        $u->setPassword($id, 'Un-Buen_Pasaje.Largo9');
    }
    $ss = new Sessions($db, $s);
    $ta = $ss->create($a, 'auth', (int)$u->byId($a)['session_epoch'], 'ip', 'x');
    $tb = $ss->create($b, 'auth', (int)$u->byId($b)['session_epoch'], 'ip', 'x');
    $prefixB = substr(Sessions::hash($tb), 0, 16);
    T::ok(!$ss->revokeByPrefix($prefixB, $a), 'A no puede revocar la de B');
    T::ok($ss->find($tb) !== null);
    T::ok($ss->revokeByPrefix($prefixB, $b));
    T::ok(!$ss->revokeByPrefix('zz', $b));
    T::eq(1, $ss->destroyAllForUser($a), 'borra las de A');
});
T::test('tokens de un solo uso: caducan, se consumen una vez y el nuevo anula al anterior', function () {
    [$db, $s] = lux_env();
    $u = new Users($db, $s);
    $id = $u->create('a@b.es', 'admin');
    $tk = new Tokens($db);
    $t1 = $tk->create($id, 'setup', 1000);
    $row = $tk->peek($t1, 'setup', 1500);
    T::ok($row !== null);
    T::eq(null, $tk->peek($t1, 'reset', 1500), 'otro propósito');
    T::eq(null, $tk->peek($t1, 'setup', 1000 + Tokens::TTL['setup'] + 1), 'caducado');
    T::ok($tk->consume((int)$row['token_id'], 1600));
    T::ok(!$tk->consume((int)$row['token_id'], 1601), 'no se reutiliza');
    T::eq(null, $tk->peek($t1, 'setup', 1700));
    $t2 = $tk->create($id, 'reset', 2000);
    $t3 = $tk->create($id, 'reset', 2010);
    T::eq(null, $tk->peek($t2, 'reset', 2020), 'el token nuevo anula el anterior');
    T::ok($tk->peek($t3, 'reset', 2020) !== null);
    T::eq(null, $tk->peek('corto', 'reset'));
    T::ok(!str_contains((string)$db->val('SELECT group_concat(token_hash) FROM tokens'), $t3), 'solo se guarda el hash');
});
T::test('usuarios: alta, contraseña, TOTP cifrado, códigos de recuperación de un solo uso', function () {
    [$db, $s] = lux_env();
    $u = new Users($db, $s);
    T::throws(LuxException::class, fn() => $u->create('no-es-correo', 'admin'), 'correo');
    T::throws(LuxException::class, fn() => $u->create('a@b.es', 'root'), 'Rol');
    $id = $u->create('A@b.es', 'admin');
    T::throws(LuxException::class, fn() => $u->create('a@B.es', 'admin'), 'Ya existe');
    T::eq('invited', $u->byId($id)['status']);
    $u->setPassword($id, 'Un-Buen_Pasaje.Largo9');
    $row = $u->byId($id);
    T::eq('active', $row['status']);
    T::ok(Password::verify('Un-Buen_Pasaje.Largo9', $row['password_hash']));
    $u->stageTotp($id, 'JBSWY3DPEHPK3PXP');
    $row = $u->byId($id);
    T::ok(!str_contains((string)$row['totp_secret'], 'JBSWY3DPEHPK3PXP'), 'secreto cifrado en reposo');
    T::eq('JBSWY3DPEHPK3PXP', $u->totpSecret($row));
    T::eq(0, (int)$row['totp_enabled']);
    $u->activateTotp($id, 55);
    T::ok(!$u->markTotpStep($id, 55), 'mismo paso: rechazado');
    T::ok($u->markTotpStep($id, 56));
    $codes = $u->generateRecoveryCodes($id);
    T::eq(10, count($codes));
    T::ok((bool)preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $codes[0]));
    T::ok($u->useRecoveryCode($id, strtoupper($codes[0])), 'insensible a mayúsculas y guion');
    T::ok(!$u->useRecoveryCode($id, $codes[0]), 'un solo uso');
    T::ok(!$u->useRecoveryCode($id, 'aaaaa-aaaaa'));
    T::eq(9, $u->remainingRecoveryCodes($id));
    $epoch = (int)$u->byId($id)['session_epoch'];
    $u->disableTotp($id);
    T::eq(0, $u->remainingRecoveryCodes($id));
    T::eq(null, $u->totpSecret($u->byId($id)));
    T::ok((int)$u->byId($id)['session_epoch'] > $epoch, 'desactivar MFA invalida sesiones');
});
T::test('autorización: por rol, estado y propietario del objeto', function () {
    $admin = ['id' => 1, 'role' => 'admin', 'status' => 'active'];
    $editor = ['id' => 2, 'role' => 'editor', 'status' => 'active'];
    T::ok(Authorizer::can($admin, 'audit.read'));
    T::ok(!Authorizer::can($editor, 'audit.read'));
    T::ok(!Authorizer::can($editor, 'users.manage'));
    T::ok(Authorizer::can($editor, 'content.write'));
    T::ok(!Authorizer::can(null, 'panel.access'));
    T::ok(!Authorizer::can(['id' => 3, 'role' => 'admin', 'status' => 'disabled'], 'panel.access'));
    T::ok(!Authorizer::can(['id' => 3, 'role' => 'inventado', 'status' => 'active'], 'panel.access'));
    T::ok(Authorizer::canOnOwned($editor, 2));
    T::ok(!Authorizer::canOnOwned($editor, 1), 'un editor no toca objetos ajenos');
    T::ok(Authorizer::canOnOwned($admin, 2));
});
T::test('auditoría: registra eventos y descarta campos sensibles', function () {
    [$db] = lux_env();
    $a = new Audit($db);
    $a->log('login.success', 1, 'a@b.es', 'user:1', ['method' => 'password', 'password' => 'x', 'reset_token' => 'y', 'totp_code' => '1'], '1.2.3.4');
    $row = $a->recent()[0];
    T::eq('login.success', $row['event']);
    T::eq('{"method":"password"}', $row['meta']);
});
