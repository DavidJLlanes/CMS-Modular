# ADR-0008 · Formatos de configuración y convenciones de rutas

Estado: Aceptada
Fecha: 2026-11-15

## Contexto
La Fase 1 debe fijar el formato de configuración y las convenciones de rutas, con URL base explícita (D3: solo raíz de dominio).

## Decisión
- **Configuración en JSON** (`config/lux.json`, versión `schema: 1`), no en PHP: un archivo de configuración no debe poder ejecutar código. `config/lux.example.json` se versiona; `config/lux.json` no.
- **URL base explícita** (`site.base_url`): obligatoria, `https` (solo `http` en localhost), **sin subcarpeta, parámetros ni credenciales**. Se normaliza sin barra final. Canonicals y enlaces absolutos se calculan siempre desde ella.
- **Rutas del sistema** (`paths.data`, `paths.publish`): relativas a la raíz de la aplicación o absolutas. En producción se recomienda absolutas y fuera del código.
- **Rutas públicas:** minúsculas ASCII; `/slug/` ↔ `slug/index.html`; la portada es `/`. Slugs con `^[a-z0-9]+(-[a-z0-9]+)*$`. Reservados: `admin`, `assets`, `forms`, `media`, `releases`, `var`.
- **Recursos con huella:** `/assets/<nombre>.<hash10>.<ext>`, caché larga e inmutable. El HTML se revalida.
- **Identificador de versión:** `AAAAMMDDHHMMSSuuuuuu-xxxx` (UTC, microsegundos, sufijo aleatorio): ordenable cronológicamente y seguro como nombre de directorio.
- **Archivos ocultos** (p. ej. `.lux-release.json`, necesario para recuperar el id de la versión activa en el modo `rename`) **nunca se sirven**: regla explícita en Nginx y `.htaccess`.
- **Errores y registros:** el operador ve un mensaje breve y un identificador de operación de 8 hex; el detalle técnico (rutas, causas) solo va a `var/logs/lux-AAAA-MM-DD.log` en JSON por líneas.

## Alternativas evaluadas
- PHP/INI/YAML: ejecución de código o dependencia (R03).
- Subcarpeta soportada: descartada por D3.

## Consecuencias
Cambiar de dominio exige cambiar `site.base_url` y republicar. La versión del esquema permite migraciones futuras.

## Verificación
`tests/ConfigTest.php`, `bin/lux doctor` y `tests/nginx-smoke.sh`.
