# Despliegue

## Perfil A · VPS con Nginx + PHP-FPM (`deploy/nginx/`)

Usuarios y permisos objetivo:

| Ruta | Propietario | Modo | Quién escribe |
|---|---|---|---|
| `/srv/luxaeterna/app` (código) | `deploy:deploy` | `0755` / archivos `0644` | solo el operador al actualizar |
| `/srv/luxaeterna/var` (datos privados) | `luxaeterna-panel:luxaeterna-panel` | `0750` | panel y publicador |
| `/srv/luxaeterna/publish` | `luxaeterna-publish:www-data` | `0755`; `releases/` `0755` | solo el publicador; Nginx lee |

Pasos resumidos (la guía completa llega en la Fase 15):

1. Crear usuarios `luxaeterna-panel` y `luxaeterna-publish`; añadir `www-data` al grupo de lectura de `publish`.
2. Copiar el código a `/srv/luxaeterna/app` y ejecutar `bin/lux install --url=https://luxaeterna.es`
   con `paths.data=/srv/luxaeterna/var` y `paths.publish=/srv/luxaeterna/publish` en `config/lux.json`.
3. Instalar `deploy/nginx/luxaeterna.conf` y `deploy/nginx/php-fpm-pool.conf`; `nginx -t` y recargar.
4. `bin/lux doctor --http` debe terminar sin errores.
5. `bin/lux publish` publica el sitio de ejemplo; `https://luxaeterna.es/` debe responder con PHP-FPM detenido.

El usuario `www-data` **no** debe tener acceso a `var/`.

## Perfil B · Hosting compartido (`deploy/apache/`)

Plantilla orientativa. Pendiente de cierre en la Fase 4: cómo mapear el docroot del proveedor
(`public_html`) a la versión activa cuando no se puede cambiar la raíz web (ver `docs/fase-1.md`).
