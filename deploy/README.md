# Despliegue

## Perfil A · VPS con Nginx + PHP-FPM (`deploy/nginx/`, `deploy/install-vps.sh`)

Disposición en `/var/www/luxaeterna.es/`:

| Ruta | Propietario | Modo | Quién escribe |
|---|---|---|---|
| `app/` (código, clon de Git) | `root:root` | `0755` (archivos sin escritura para grupo/otros) | solo el operador, al actualizar |
| `app/config/lux.json` | `root:luxaeterna` | `0640` | nadie en uso normal |
| `var/` (datos privados) | `luxaeterna:luxaeterna` | `0750` | panel y CLI (mismo usuario) |
| `publish/` (versiones y `current`) | `luxaeterna:luxaeterna` | `0755` | el publicador; Nginx solo lee |
| `acme/` | `root:root` | `0755` | solo certbot (validación HTTP-01) |

Un único usuario de sistema (`luxaeterna`) ejecuta el panel (pool PHP-FPM propio) y la CLI. `www-data` **no** puede entrar en `var/`
(`0750`) y el código no es escribible por la aplicación. Separar panel y publicador en dos usuarios queda como endurecimiento
opcional para la Fase 14.

### Instalación o actualización

Requisitos: Ubuntu/Debian con Nginx, acceso root, y un registro DNS A de `luxaeterna.es` hacia la IP del servidor.

```bash
curl -fsSL https://raw.githubusercontent.com/DavidJLlanes/LuxAeterna/main/deploy/install-vps.sh -o /root/install-luxaeterna.sh
less /root/install-luxaeterna.sh          # revísalo antes de ejecutarlo
bash /root/install-luxaeterna.sh --domain=luxaeterna.es --base=/var/www/luxaeterna.es --email=tu@correo
```

El script es idempotente: repetirlo actualiza el código (`git merge --ff-only`) y reaplica la configuración. No modifica otros
sitios de Nginx: escribe solo `luxaeterna.es.conf`, valida con `nginx -t` antes de recargar y, si falla, retira su configuración.
Opciones: `--no-ipv6`, `--skip-ssl` (si ya tienes certificado), `--repo=`, `--branch=`.

### Verificación posterior

```bash
runuser -u luxaeterna -- php /var/www/luxaeterna.es/app/bin/lux doctor --http
bash /var/www/luxaeterna.es/app/tests/nginx-smoke.sh   # prueba de humo (usa puertos y sockets temporales)
curl -I https://luxaeterna.es/ ; curl -s -o /dev/null -w '%{http_code}\n' https://luxaeterna.es/var/   # 200 y 404
```

### Retirada

```bash
rm /etc/nginx/sites-enabled/luxaeterna.es.conf /etc/nginx/sites-available/luxaeterna.es.conf && nginx -t && systemctl reload nginx
rm /etc/php/*/fpm/pool.d/luxaeterna-panel.conf && systemctl reload php*-fpm
# Los datos (var/, publish/) solo se borran a mano: rm -rf /var/www/luxaeterna.es
```

## Perfil B · Hosting compartido (`deploy/apache/`)

Plantilla orientativa. Pendiente de cierre en la Fase 4: cómo mapear el docroot del proveedor
(`public_html`) a la versión activa cuando no se puede cambiar la raíz web (ver `docs/fase-1.md`).
