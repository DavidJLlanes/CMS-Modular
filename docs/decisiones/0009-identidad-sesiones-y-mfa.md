# ADR-0009 · Identidad, sesiones y segundo factor
Estado: Aceptada
Fecha: 2026-11-15

## Contexto
El panel es la superficie más expuesta (T-02…T-07 del modelo de amenazas). Debe funcionar igual en VPS y en hosting compartido (R12), sin dependencias de terceros (R03) y sin depender de `$_SESSION` ni de servicios externos.

## Decisión
- **Contraseñas**: Argon2id (19 MiB, t=2, p=1) con reserva a bcrypt; longitud 12–128; rehash transparente. Verificación ficticia para cuentas inexistentes (sin enumeración por tiempo ni por mensaje).
- **Sesiones en SQLite**: token aleatorio de 256 bits en cookie; en base solo su SHA-256. Caducidad por inactividad (30 min) y absoluta (8 h). Rotación tras login, MFA y cambio de contraseña. `users.session_epoch` invalida en bloque todas las sesiones (cambio de rol, contraseña, desactivar MFA, desactivar cuenta).
- **Cookie** `__Secure-lux_sid` (en HTTP local, `lux_sid`): `HttpOnly; Secure; SameSite=Strict; Path=/admin`.
- **CSRF**: token HMAC sin estado ligado al hash de la sesión (o `anon` en formularios previos al login) con caducidad, más comprobación de `Origin`/`Referer` contra `site.base_url`.
- **MFA TOTP** (RFC 6238, implementación propia, verificada con los vectores del RFC): secreto cifrado en reposo (libsodium, clave en `var/secrets/app.key`), ventana ±1, rechazo de reutilización de código, 10 códigos de recuperación de un solo uso (solo su HMAC en base). Obligatorio por defecto (`security.mfa = required`); sin QR (R03): clave en texto y enlace `otpauth://`.
- **Alta y recuperación** solo por CLI (`admin:create`, `admin:reset`, `admin:mfa-reset`): enlace de un solo uso con caducidad (60/30 min), hash en base. No hay «olvidé mi contraseña» por correo en esta fase: no hay vector remoto de recuperación que atacar.
- **Límite de intentos** por IP y por cuenta (hash HMAC de la clave), mensajes genéricos. La IP real solo se toma de `X-Forwarded-For` si la conexión viene de `security.trusted_proxies`.
- **Autorización** en servidor por acción y por objeto, denegación por defecto (`Authorizer`). Roles: `admin`, `editor`.
- **Auditoría** de accesos, MFA, cambios de cuenta y sesiones; nunca guarda secretos.
- **Cabeceras**: CSP estricta del panel emitida por la aplicación (sin JavaScript ni estilos en línea); CSP, HSTS y nosniff del sitio público desde Nginx/Apache.

## Alternativas evaluadas
- `$_SESSION` nativo: depende de la configuración del hosting y de archivos en `/tmp` compartidos.
- JWT sin estado: no permite revocar.
- Recuperación por correo: añade un canal de ataque y una dependencia de SMTP; se evaluará en una fase posterior con límites propios.
- WebAuthn: deseable más adelante (requiere JavaScript); TOTP cubre el requisito sin dependencias.

## Consecuencias
- Un fallo de SQLite deja el panel sin acceso, pero el sitio público (estático) sigue sirviéndose.
- El primer administrador se crea desde la terminal; es un paso de instalación documentado.
