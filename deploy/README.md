# Despliegue

## Perfil A · VPS con Nginx + PHP-FPM (`deploy/nginx/`, `deploy/install-vps.sh`)

Disposición en `/var/www/tu-dominio.es/`:

| Ruta | Propietario | Modo | Quién escribe |
|---|---|---|---|
| `app/` (código, clon de Git) | `root:root` | `0755` (archivos sin escritura para grupo/otros) | solo el operador, al actualizar |
| `app/config/lux.json` | `root:cms-modular` | `0640` | nadie en uso normal |
| `var/` (datos privados) | `cms-modular:cms-modular` | `0750` | panel y CLI (mismo usuario) |
| `publish/` (versiones y `current`) | `cms-modular:cms-modular` | `0755` | el publicador; Nginx solo lee |
| `acme/` | `root:root` | `0755` | solo certbot (validación HTTP-01) |

Un único usuario de sistema (`cms-modular`) ejecuta el panel (pool PHP-FPM propio) y la CLI. `www-data` **no** puede entrar en `var/`
(`0750`) y el código no es escribible por la aplicación. Separar panel y publicador en dos usuarios queda como endurecimiento
opcional para la Fase 14.

### Instalación o actualización

Requisitos: Ubuntu/Debian con Nginx, acceso root, y un registro DNS A de `tu-dominio.es` hacia la IP del servidor.

```bash
curl -fsSL https://raw.githubusercontent.com/DavidJLlanes/CMS-Modular/main/deploy/install-vps.sh -o /root/install-cms-modular.sh
less /root/install-cms-modular.sh          # revísalo antes de ejecutarlo
bash /root/install-cms-modular.sh --domain=tu-dominio.es --base=/var/www/tu-dominio.es --email=tu@correo
```

**Si ya hay cosas instaladas:** el script solo instala los paquetes que faltan y nunca actualiza los existentes; si ya tienes PHP,
usa su misma versión (≥ 8.1) sin cambiar de rama. **Si el dominio ya tiene certificado de certbot**, lo reutiliza: lo busca con
`certbot certificates` (aunque se llame `tu-dominio.es-0001`) o puedes indicarlo con `--cert-dir=/etc/letsencrypt/live/NOMBRE`; no pide
otro. El puerto 80 sirve `/.well-known/acme-challenge/` desde `/var/www/tu-dominio.es/acme` para que la renovación automática siga
funcionando sea cual sea el método con el que se emitió. Si `/var/www/tu-dominio.es` ya existe, conserva su dueño y permisos (solo
asegura que Nginx pueda atravesarlo). Se niega a continuar, sin cambiar nada, si `app/` existe y no es un clon de Git, o si otra
configuración de Nginx ya declara `server_name tu-dominio.es` (tu sitio actual): revísala y, si es provisional, repite con `--replace-vhost`, que guarda
una copia en `/root/lux-nginx-backup-<fecha>/`, desactiva la antigua (no borra el original de `sites-available`) y la restaura sola si algo falla. Antes de reescribir su propio `tu-dominio.es.conf` guarda una copia
`.bak-<fecha>`.

El script es idempotente: repetirlo actualiza el código (`git merge --ff-only`) y reaplica la configuración. No modifica otros
sitios de Nginx: escribe solo `tu-dominio.es.conf`, valida con `nginx -t` antes de recargar y, si falla, retira su configuración.
Opciones: `--no-ipv6`, `--skip-ssl` (si ya tienes certificado), `--repo=`, `--branch=`.

### Verificación posterior

```bash
runuser -u cms-modular -- php /var/www/tu-dominio.es/app/bin/lux doctor --http
bash /var/www/tu-dominio.es/app/tests/nginx-smoke.sh   # prueba de humo (usa puertos y sockets temporales)
curl -I https://tu-dominio.es/ ; curl -s -o /dev/null -w '%{http_code}\n' https://tu-dominio.es/var/   # 200 y 404
```

### Retirada

```bash
rm /etc/nginx/sites-enabled/tu-dominio.es.conf /etc/nginx/sites-available/tu-dominio.es.conf && nginx -t && systemctl reload nginx
rm /etc/php/*/fpm/pool.d/cms-modular-panel.conf && systemctl reload php*-fpm
# Los datos (var/, publish/) solo se borran a mano: rm -rf /var/www/tu-dominio.es
```

## Perfil B · Hosting compartido (`deploy/apache/`)

Plantilla orientativa. Pendiente de cierre en la Fase 4: cómo mapear el docroot del proveedor
(`public_html`) a la versión activa cuando no se puede cambiar la raíz web (ver `docs/fase-1.md`).
