# ADR-0010 · Contenido, revisiones, URLs y previsualización
Estado: Aceptada
Fecha: 2026-11-15

## Contexto
La Fase 3 introduce el flujo editorial. Debe permitir editar sin afectar a lo publicado, recuperar versiones, cambiar URLs sin romper enlaces y previsualizar con las plantillas reales, sin que ningún borrador pueda filtrarse a archivos públicos (R01, R06).

## Decisión
- **Identidad estable**: cada contenido tiene `uid` (`c_…`) que no cambia con el slug. Menús, portada y redirecciones referencian `uid`, nunca rutas.
- **Dos punteros**: `current_rev` (en edición) y `published_rev` (publicada). Guardar solo mueve `current_rev`. El generador lee **solo** `published_rev` (`SiteBuilder`); no existe ninguna consulta del generador a revisiones en edición.
- **Revisiones inmutables**: guardar crea una revisión (si hay cambios); restaurar crea otra nueva (`kind=restore`). Historial acotado a 100 revisiones, conservando siempre la actual y la publicada.
- **Bloqueo optimista**: `contents.version` sube con cada guardado, restauración, publicación o cambio de estado. Toda mutación envía la versión leída; si no coincide → `ConflictException` (HTTP 409) y el editor muestra el texto del usuario con la versión actual para reintentar. La versión ajena queda en el historial.
- **Autoguardado**: una revisión especial (`number=0`) por contenido, sin subir versión ni crear historial. Se ofrece recuperar si es más reciente que la revisión en edición.
- **Estados**: `draft`, `published`, `trashed`. La papelera retira del sitio y libera el slug; restaurar devuelve a borrador (nunca republica solo). El borrado definitivo solo existe desde la papelera y solo para administradores; elimina revisiones, enlaces de menú y redirecciones propias.
- **Rutas**: `page` → `/slug/`; `post` → `/blog/slug/`; tipos propios → `/prefijo/slug/`. Reservados: `admin, assets, forms, media, releases, var, categoria, etiqueta, sitemap, robots, feed, 404, favicon`, el prefijo del blog y los prefijos de tipos propios. La portada (ajuste `home_uid`) se sirve en `/` y su ruta propia redirige a `/`.
- **Redirecciones**: al publicar con un slug distinto al publicado se registra `antigua → nueva`, se encadenan (sin saltos intermedios) y se eliminan si la ruta antigua vuelve a usarse. Hasta que la Fase 4 genere reglas HTTP 301, el sitio estático emite una página mínima con `meta refresh`, `canonical` y `noindex`, que funciona en cualquier hosting.
- **Bloques serializables** (schema 1): párrafo, título (2–6), lista, cita, imagen y galería. Imagen y galería se guardan por `ref` estable pero **no se publican hasta la Fase 6**: la publicación falla con un mensaje claro en vez de omitirlas en silencio. El texto es siempre texto plano escapado; no hay HTML libre, por tanto no hace falta sanitizador.
- **Editor sin JavaScript obligatorio**: sintaxis de texto → bloques (`Blocks::fromText/toText`, sin pérdidas, con escape de marcadores). El único JavaScript del panel es `autosave.js` (mejora progresiva, CSP `script-src 'self'`).
- **Tipos y campos configurables** por esquema validado en `content.types` (text, textarea, number, bool, select; obligatorio, máximo, opciones). Los valores se guardan por revisión.
- **Previsualización**: `SiteRenderer` es el mismo código que usa el publicador, con otra hoja de estilos servida por el panel y `<meta robots noindex>`. Se sirve en `iframe sandbox=""` con CSP propia, `X-Robots-Tag: noindex`, `Cache-Control: no-store, private` y sin escribir nada en `publish/`.
- **Permisos**: editor → contenido (leer, escribir, publicar) y términos; administrador → además menús, ajustes, generación del sitio y borrado definitivo.

## Alternativas evaluadas
- Slug dentro del contenido (no de la revisión): rompería la separación publicado/edición.
- Autoguardar como revisión normal: llena el historial.
- Editor de bloques con JavaScript ahora: se pospone a la Fase 7; la estructura de datos ya es la definitiva.
- Redirecciones solo con reglas Nginx: no funcionan en hosting compartido (R12).

## Consecuencias
- Publicar contenido (marcarlo como publicado) y generar el sitio (`bin/lux publish` o «Generar y activar») son pasos distintos hasta que la Fase 4 introduzca trabajos de publicación.
- Cambiar el esquema de un tipo propio no reescribe revisiones antiguas: los campos nuevos aparecen vacíos y los eliminados se ignoran.
