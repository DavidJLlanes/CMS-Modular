# Fase 2 · Identidad y seguridad del panel

Estado: **implementada y probada en el sandbox** (Nginx + PHP-FPM reales). Pendiente: verificación en el VPS de IONOS (ver «Puesta en marcha»).

## Qué incluye
| Área | Dónde |
|---|---|
| Base de datos SQLite (WAL) y migraciones | `src/Core/Database.php`, `Migrator.php`, `migrations/001_security.sql` |
| Secretos de aplicación (HKDF, cifrado libsodium) | `src/Security/Secrets.php` → `var/secrets/app.key` (0600) |
| Contraseñas, TOTP, sesiones, CSRF, límites, tokens, auditoría, autorización | `src/Security/*` |
| Panel (login, MFA, alta/recuperación, cuenta, sesiones, auditoría) | `src/Admin/*`, `public/admin/index.php`, `public/admin/assets/admin.css` |
| CLI de cuentas | `bin/lux admin:create|list|reset|mfa-reset|revoke-sessions|disable|enable|role`, `bin/lux migrate` |
| Cabeceras | `deploy/nginx/snippets/lux-security-headers.conf`, `deploy/apache/htaccess-ejemplo`, CSP del panel en `App::secure()` |
| Decisiones | [ADR-0009](decisiones/0009-identidad-sesiones-y-mfa.md) |

## Pruebas
- `php tests/run.php`: 55 pruebas (RFC 6238, sesiones, CSRF, límites, IDOR, autorización, escapado, proxy, flujo completo del panel sin servidor).
- `tests/http-smoke.sh`: panel por HTTP real (servidor integrado de PHP).
- `tests/nginx-smoke.sh`: Nginx + PHP-FPM reales con la configuración de `deploy/` (cabeceras, CSP, límite de cuerpo, panel sin sesión).

Casos exigidos por el plan: acceso sin sesión (redirige al login), sesión caducada (inactividad, época, logout), peticiones falsificadas (CSRF sin token / de otra sesión / origen ajeno / Referer engañoso), enumeración de cuentas (misma respuesta para correo inexistente, inactivo o contraseña mala), reutilización de código TOTP y de recuperación, IDOR en sesiones.

## Puesta en marcha en el VPS
```
git -C /var/www/tu-dominio.es/app pull -q
bash /var/www/tu-dominio.es/app/deploy/install-vps.sh --domain=tu-dominio.es --base=/var/www/tu-dominio.es
runuser -u cms-modular -- php /var/www/tu-dominio.es/app/bin/lux admin:create TU_CORREO --role=admin
```
Abre el enlace que imprime (caduca en 60 min), fija la contraseña, entra y configura el segundo factor.

Si pierdes el móvil y los códigos: `admin:mfa-reset TU_CORREO`. Si olvidas la contraseña: `admin:reset TU_CORREO`.

## Límites conocidos
- Sin recuperación por correo (decisión del ADR-0009).
- Sin QR: se muestra la clave y un enlace `otpauth://` (R03).
- Un solo servidor de aplicación: los límites de intentos viven en SQLite.
- Los límites de la Fase 2 no cubren aún el sitio público (es estático).
