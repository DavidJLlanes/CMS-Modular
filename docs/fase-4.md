# Fase 4 · Motor de publicación, coherencia y reversión

## Qué hay
- **Trabajos de publicación**: cola persistente con estados, registro por trabajo, reintentos acotados y recuperación tras caída (ADR-0011).
- **Comprobaciones previas** ampliadas: errores que bloquean y avisos editoriales.
- **Sin duplicados**: si nada cambió no se crea otra versión (se puede forzar).
- **Recursos antiguos retenidos** y metadatos completos por versión.
- **Reversión** con validación previa, desde el panel o la CLI.
- **Redirecciones 301** reales: `.htaccess` por versión (Apache) y servicio validado para Nginx.
- **Panel «Publicaciones, versiones y reversión»** (solo administradores).

## Comandos
| Comando | Qué hace |
|---|---|
| `bin/lux publish [--force] [--queue]` | Publica lo publicado en el panel (omite si no hay cambios) |
| `bin/lux jobs` / `jobs:run` | Lista los trabajos / ejecuta los pendientes (cron) |
| `bin/lux releases` | Versiones con fecha y páginas; `*` = activa |
| `bin/lux rollback <id>` | Reactiva una versión (validada) |

## Configuración (`config/lux.json`, sección `publishing`)
`keep_releases` (5), `keep_assets_releases` (2), `min_free_mb` (50), `max_attempts` (3), `max_files` (50000), `job_grace` (60 s), `rules_status` (ruta del estado de reglas; la fija el instalador), `rules_wait` (8 s).

## Actualizar el VPS
```
git -C /var/www/luxaeterna.es/app pull -q && bash /var/www/luxaeterna.es/app/deploy/install-vps.sh --domain=luxaeterna.es --base=/var/www/luxaeterna.es
```
El instalador migra la base (003), crea `/etc/nginx/lux-rules/`, añade `include` al vhost, instala el servicio de reglas y lo activa.

## Qué se probó
- Pruebas automáticas (112): trabajos, reintentos, huérfanos, preflight, retención, reglas (incluida inyección de configuración), panel.
- Resiliencia con procesos reales: `kill -9` en plena publicación, dos publicaciones simultáneas, disco casi lleno (tmpfs de 1 MB), plantilla inexistente, enlace interno roto. En todos, la versión activa sigue sirviéndose y la anterior es restaurable.
- Exportación: una versión copiada a otra carpeta se sirve con un servidor estático y todos sus enlaces internos resuelven.
- Nginx real (`tests/nginx-smoke.sh`): el servicio de reglas instala un 301, rechaza una regla inyectada y deja la anterior.

## Límites conocidos
- La retención por bytes y la limpieza de recursos huérfanos más allá de las versiones guardadas llegan con las imágenes (Fase 6).
- Las reglas de Nginx se aplican en segundos por `systemd`; hasta entonces (o si el servicio falla) redirigen las páginas-puente.
