# Fase 1 · Esqueleto, instalación y separación del sitio público

Estado: **completada en el perfil A (VPS con Nginx)**; perfil B (hosting compartido) parcial, ver "Riesgos y pendientes".

## 1. Resultado ejecutable

```bash
bin/lux install --url=https://luxaeterna.es   # configuración + directorios privados + diagnóstico
bin/lux publish                               # genera y activa el sitio de ejemplo
bin/lux releases                              # lista versiones (* = activa)
bin/lux rollback <id>                         # reactiva una versión
bin/lux doctor [--json] [--http]              # diagnóstico; --http comprueba que var/ no se sirve
php tests/run.php                             # 24 pruebas unitarias y de integración
bash tests/nginx-smoke.sh                     # 30 comprobaciones con Nginx y PHP-FPM reales
```

Flujo vertical: `examples/sitio-demo/content.json` → plantilla `components/layouts/base/` → versión en directorio temporal → preflight → `releases/<id>/` → activación atómica de `current` → Nginx sirve `index.html`.

## 2. Mapa de cambios

| Área | Archivos | Responsabilidad |
|---|---|---|
| `bin/` | `lux` | CLI: install, doctor, publish, releases, rollback |
| `src/Core/` | `Autoloader`, `Config`, `Doctor`, `Installer`, `Fs`, `Logger`, `Operation`, `LuxException` | Arranque mínimo, configuración, diagnóstico, errores, logs |
| `src/Content/` | `ContentLoader`, `Page` | Modelo mínimo de página y validación de slugs/bloques |
| `src/Publishing/` | `Publisher`, `ReleaseStore`, `Activator`, `Preflight`, `Template`, `BlockRenderer` | Generación, versiones, activación atómica, comprobación previa |
| `components/layouts/base/` | `template.html`, `style.css` | Diseño base (precursor del contrato de la Fase 5) |
| `public/admin/` | `index.php` | Entrada del panel, aislada del sitio público; sin funciones hasta la Fase 2 |
| `deploy/` | `nginx/`, `apache/`, `README.md` | Ejemplos de servidor, pool FPM, permisos |
| `tests/` | `run.php`, `*Test.php`, `nginx-smoke.sh` | Pruebas sin dependencias |
| `examples/` | `sitio-demo/` | Contenido sintético |

## 2b. Despliegue en el VPS

`deploy/install-vps.sh` instala o actualiza en `/var/www/luxaeterna.es/` (ver `deploy/README.md`). Se probó aquí con Nginx, PHP-FPM y un certificado autofirmado: HTTP/2 correcto, panel ejecutándose como el usuario `luxaeterna`, `/var/`, `/config/` y archivos ocultos con 404, y segunda ejecución sin cambios ni errores. En la prueba se encontraron y corrigieron dos fallos: la repetición del script dejaba la configuración ilegible para el usuario de la aplicación, y un fallo de DNS cortaba el script sin mensaje. No se ha ejecutado todavía en el servidor real.

### Verificación en el VPS real (`vps-real`, IONOS, servidor de Nginx y correo)

Ejecución del 2026-11-15 de `deploy/install-vps.sh --replace-vhost` (se sustituyó un vhost provisional de certbot, con copia de seguridad; se reutilizó su certificado):

| Comprobación | Resultado |
|---|---|
| Entorno | PHP 8.3.6, GD con WebP, Argon2id, ~100 GB libres, activación por enlace simbólico |
| `bin/lux doctor --http` | 11 comprobaciones correctas, incluida la de exposición de `var/` por HTTP (canario no servido) |
| `https://luxaeterna.es/`, `/sobre-nosotros/`, `/admin/` | 200, 200, 200 |
| `/var/`, `/.lux-release.json` | 404, 404 |
| `tests/nginx-smoke.sh` | Pendiente de repetir con el sitio ya publicado. La primera ejecución fue antes de publicar y solo fueron concluyentes las comprobaciones del panel (todas correctas) |

Hallazgo: el vhost provisional anterior tenía como raíz la misma carpeta donde se instala la aplicación, lo que exponía `app/` por HTTP hasta que se sustituyó. El script detecta ahora ese caso (otro `server_name` igual) y se detiene salvo que se use `--replace-vhost`.

## 3. Decisiones

- [ADR-0008](decisiones/0008-configuracion-y-rutas.md): configuración JSON, URL base explícita, rutas, id de versión, errores/logs.
- Plantillas de capacidades limitadas: marcadores `{{ x }}` (escapados) y `{{{ x }}}` (HTML preparado por el generador); sin lógica ni PHP (ADR-0002).
- Sin PHPUnit ni Composer: runner propio de ~100 líneas (R03, ADR-0005). **Dependencias de terceros: 0.**
- Defecto encontrado y corregido durante la fase: los ids de versión con segundos y sufijo aleatorio no ordenaban cronológicamente dentro del mismo segundo, lo que podía hacer que la retención borrase una versión reciente. Se pasó a microsegundos.

## 4. Evidencia reproducible

Entorno: `sintético-ref` ampliado (Ubuntu, PHP 8.3.6, Nginx y PHP-FPM 8.3 del repositorio de paquetes, ejecución como root en puertos altos). No es el VPS real.

| Cierre exigido | Evidencia |
|---|---|
| Instalación repetible en limpio | `tests/CoreTest.php` (idempotente, no sobrescribe sin `--force`); `bin/lux install` ejecutado sobre árbol limpio |
| Página estática accesible sin PHP | `nginx-smoke.sh`: portada, página interior, 404 y CSS con respuesta correcta **con PHP-FPM detenido**; `/admin/` devuelve 502 sin FPM |
| Acceso externo bloqueado a SQLite, copias, originales, secretos, temporales | 11 rutas (`/var/`, `/var/db/`, `/src/…`, `/config/lux.json`, `/bin/lux`, `/publish/`, `/releases/`, `/docs/`, `/deploy/…`, `/.git/config`, `/.lux-release.json`) devuelven 404; `.php` en la raíz pública devuelve 404 |
| Detección de datos privados expuestos | `doctor --http` con servidor que sirve el repositorio completo: **ERROR** y código de salida 1. Con la URL base sin respuesta: **AVISO** (no da un falso "correcto") |
| Activación atómica y reversión | Pruebas en modos `symlink` y `rename`: activar, segunda versión, volver atrás, contenido inválido sin afectar a la activa, retención, bloqueo de publicación doble, recuperación tras caída entre renombrados |
| Errores unificados | Mensaje breve + id de operación en pantalla; detalle (rutas) solo en `var/logs` (`ConfigTest`: el mensaje no contiene rutas) |

Resultado de la última ejecución: `php tests/run.php` → 24 correctas, 0 con fallo; `tests/nginx-smoke.sh` → TODO CORRECTO.

## 5. Estado de requisitos

| Requisito | Estado tras la Fase 1 |
|---|---|
| R01 | Cerrado para el flujo mínimo (se amplía en las fases 3–4) |
| R10 | Parcial: publicación atómica y reversión probadas; faltan fallos de disco/configuración (Fase 4) y todo lo de sesiones (Fase 2) |
| R11 / R12 | Parcial: instalación y diagnóstico funcionan; falta medir en `vps-real` y `sintético-b` |
| R03 | Cumplido: 0 dependencias de terceros; panel sin JS ni CSS externos |

## 6. Riesgos y pendientes

1. **Hosting compartido (perfil B):** el modo `rename` está implementado y probado, pero falta decidir cómo mapear el docroot del proveedor cuando no se puede apuntar a `publish/current` ni servir `/admin` desde otra raíz. Opciones a evaluar en la Fase 4: (a) `current` como docroot real con el panel en un subdirectorio copiado en cada publicación; (b) docroot fijo con `rename` de un subdirectorio y reglas `.htaccess`. Hasta entonces `deploy/apache/` es solo una plantilla.
2. **Instalador web de un solo uso** (ADR-0007): no implementado; depende de la autenticación y del token de instalación de la Fase 2.
3. **Cabeceras:** CSP y HSTS del sitio público se activan en la Fase 2; el panel ya envía CSP restrictiva.
4. **Pruebas en el VPS real:** `doctor --http` y las comprobaciones externas ya pasan (ver arriba); falta repetir `nginx-smoke.sh` con el sitio publicado.
5. **Medición:** no se han tomado todavía cifras de rendimiento (protocolo de la Fase 0); el primer informe va en la Fase 4 con el generador completo.

## 7. Dependencia de la fase siguiente

La Fase 2 (identidad y seguridad del panel) usa `src/Core/` (config, logs, operación), `public/admin/` y la pool FPM aislada de `deploy/nginx/`.
