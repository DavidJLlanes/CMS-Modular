# ADR-0011 · Motor de publicación, coherencia y reversión
Estado: Aceptada
Fecha: 2026-11-15

## Contexto
La Fase 4 debe garantizar que publicar nunca deja el sitio roto, que dos publicaciones simultáneas no se pisan, que una caída del proceso es recuperable y que se puede volver a una versión anterior, también en hosting compartido (R01, R02, R05, R12).

## Decisión
- **Cola de trabajos persistente** (`publish_jobs`, `publish_job_log`, migración 003). Estados `queued → running → succeeded | failed | cancelled`. Tipos `publish` y `rollback`. Una publicación pendiente equivalente se reutiliza (varios clics = un trabajo). El panel encola y ejecuta el trabajo en línea; `bin/lux jobs:run` (cron) ejecuta lo pendiente, y es la vía del perfil B.
- **Un solo escritor**: `flock` no bloqueante en `publish/.publish.lock` durante generar → comprobar → activar. El sistema operativo libera el bloqueo si el proceso muere, de modo que un trabajo «en ejecución» sin latido y con el bloqueo libre es huérfano y se reencola (`recoverStale`).
- **Errores transitorios vs permanentes**: `TransientException` (bloqueo ocupado) se reintenta con espera exponencial acotada (máx. `publishing.max_attempts`, 3). Cualquier otro fallo (preflight, configuración, plantilla) falla de inmediato con un mensaje comprensible y el detalle en el registro de la aplicación. En ningún caso se toca la versión activa.
- **Instantánea coherente**: el generador construye en `releases/.building-<id>`, comprueba y solo entonces renombra a `releases/<id>` y activa. Los restos `.building-*` de una caída se limpian al empezar la siguiente publicación (con el bloqueo tomado).
- **Hash de contenido**: se calcula sobre el árbol generado (contenido + configuración + plantilla). Si coincide con el de la versión activa no se crea otra versión (`skipped`), salvo «forzar».
- **Preflight**: devuelve *errores* (bloquean) y *avisos* (editoriales, se guardan en los metadatos y se muestran). Errores: faltan `index.html`/`404.html`, enlaces simbólicos, archivos ocultos o privados/ejecutables, colisiones de rutas por mayúsculas, enlaces internos rotos (también relativos), `javascript:`/`data:`, páginas sobre el límite, demasiados archivos, canónico fuera del sitio, `noindex` accidental, sin título. Avisos: sin descripción o demasiado larga, título repetido o largo, sin canónico, cadenas de redirecciones, página pesada.
- **Recursos con huella retenidos**: los `assets_own` de las últimas `publishing.keep_assets_releases` (2) versiones se enlazan (o copian) a la nueva, para que páginas en caché de visitantes no pierdan su CSS tras publicar. Los metadatos de la versión (`.lux-release.json`) listan `assets`, `assets_own`, `hash`, `files`, `bytes`, `warnings`.
- **Guarda de espacio**: antes de generar se exige `publishing.min_free_mb` (50) libres. Con el disco lleno la publicación falla limpia (probado con un tmpfs real).
- **Retención**: se conservan `publishing.keep_releases` versiones y siempre la activa.
- **Reversión = trabajo**: valida que la versión existe, es completa y su identificador coincide; toma el mismo bloqueo; activa con la misma operación atómica. Reactiva también sus reglas HTTP.
- **Redirecciones 301 reales**: cada versión incluye `.lux-redirects.nginx.conf` y un `.htaccess` (Apache/LiteSpeed; viaja y revierte con la versión). Para Nginx, la aplicación no puede escribir configuración del servidor: deja una propuesta (`publish/rules/redirects.nginx.conf` + `apply.request`) y un servicio `systemd` root (`deploy/lux-apply-rules.sh`) valida **cada línea** con una expresión que solo admite `location = /ruta/ { return 301 /destino/; }`, instala en `/etc/nginx/lux-rules/<dominio>.conf`, ejecuta `nginx -t`, recarga y restaura lo anterior si falla; el resultado queda en `/var/lib/lux-rules/<dominio>.status.json` (propiedad de root). Si el servicio no existe o falla, las páginas-puente (`meta refresh`) de la Fase 3 siguen redirigiendo.

## Alternativas evaluadas
- Dejar que la aplicación escriba el include de Nginx directamente: un fallo de la aplicación equivaldría a ejecutar configuración arbitraria como root. Descartado.
- Cola con demonio permanente: contradice R12 (hosting barato). La cola en SQLite se procesa en línea o por cron.
- Detectar cambios por marcas de fecha: poco fiable; el hash del árbol generado es exacto y barato.

## Consecuencias
- En el perfil B (compartido) basta con Apache: el `.htaccess` de la versión activa lleva los 301; no hace falta servicio root.
- Cada despliegue en VPS instala dos unidades `systemd` (`lux-rules-<dominio>.path/.service`) y un script en `/usr/local/sbin`.
