# Fase 3 · Contenido, navegación y flujo editorial

Estado: **implementada y probada en el sandbox** (Nginx + PHP-FPM reales). Pendiente: verificación en el VPS de IONOS (ver «Puesta en marcha»).

## Qué incluye
| Requisito de la fase | Dónde |
|---|---|
| Páginas, entradas, categorías, etiquetas, menús, ajustes; tipos y campos por esquema; identificadores estables | `migrations/002_content.sql`, `src/Content/{TypeRegistry,ContentRepository,Taxonomy,Menus,Settings}.php` |
| Borradores, publicación, retirada, papelera, revisiones, restauración, autoguardado, conflictos por versión | `ContentRepository` (+ `ConflictException`), `public/admin/assets/autosave.js` |
| Revisión publicada separada de la de edición | punteros `current_rev` / `published_rev`; `SiteBuilder` solo lee la publicada |
| Previsualización móvil/escritorio, privada, noindex, no-store, con las plantillas reales | `src/Publishing/{Preview,SiteRenderer}.php`, `ContentController::preview` |
| Slugs, reservados, colisiones, cambios de URL y redirecciones | `Slug`, `TypeRegistry::reservedForPages`, `ContentRepository::publish`, páginas de redirección en `Publisher` |
| Portada, blog, listados y menús configurables | `SiteBuilder` (blog, categorías, etiquetas, tipos propios), `SiteController` |
| Bloques serializables con referencias estables a imágenes/galerías | `Blocks` (schema 1) |
| Panel | `src/Admin/{ContentController,SiteController,Ui}.php` |
| Decisiones | [ADR-0010](decisiones/0010-contenido-revisiones-y-previsualizacion.md) |

## Mapa de archivos nuevos
`migrations/002_content.sql` · `src/Content/*` (modelo y reglas) · `src/Publishing/{SiteRenderer,Preview}.php` · `src/Admin/{ContentController,SiteController,Ui}.php` · `public/admin/assets/autosave.js` · `tests/{ContentRepoTest,SitePublishTest,PanelContentTest}.php`.

## Pruebas (`php tests/run.php`: 89 pruebas)
- **Modelo**: slug, bloques (ida y vuelta y rechazos), tipos y campos, revisiones, restaurar = revisión nueva, bloqueo optimista, autoguardado, publicado vs edición, redirecciones encadenadas, papelera (liberar/ocupar slug, portada protegida, borrado en cascada), límite de historial.
- **Publicación**: solo lo publicado llega a los archivos (se comprueba que el texto de borradores y papelera **no aparece en ningún archivo**), listados sin borradores, menús por `uid`, aviso por contenido no publicado, imágenes bloqueadas hasta la Fase 6, previsualización sin efectos en `publish/`.
- **Panel por HTTP**: todas las rutas exigen sesión; CSRF (sin token, token de otra sesión, origen ajeno) en cada mutación; permisos por rol; conflicto 409 conservando el texto; autoguardado JSON; validación que conserva lo escrito; previsualización privada; escapado de XSS en listados, editor, revisiones y vista previa; flujo completo hasta los archivos generados.
- `tests/nginx-smoke.sh` (Nginx + PHP-FPM reales) ampliado con el JS del panel y la nueva CSP.

## Puesta en marcha en el VPS
```
git -C /var/www/luxaeterna.es/app pull -q
bash /var/www/luxaeterna.es/app/deploy/install-vps.sh --domain=luxaeterna.es --base=/var/www/luxaeterna.es
```
La migración 002 se aplica sola. Después, en el panel: **Contenido → Nuevo: Página**, escribe, **Publicar**; en **Ajustes** elige la portada y pulsa **Generar y activar ahora** (o `runuser -u luxaeterna -- php /var/www/luxaeterna.es/app/bin/lux publish`).

## Sintaxis del editor
Bloques separados por una línea en blanco: `## Título` (hasta `######`), `- elemento` (lista), `> cita`, `[imagen:ref|alternativo|pie]` y `[galeria:ref]` (estos dos, reservados para la Fase 6), o texto normal (párrafo). Para empezar un párrafo con uno de esos marcadores, antepón `\`.

## Límites conocidos y siguiente fase
- Generar el sitio sigue siendo síncrono; la Fase 4 añade trabajos, bloqueo, preflight ampliado, reglas HTTP de redirección y reversión.
- Sin sitemap, feeds ni metadatos SEO avanzados (Fase 9) ni biblioteca de imágenes (Fase 6).
- Jerarquía de páginas (padre/hijo) no incluida: las páginas viven en `/slug/`.
- El listado del blog no pagina todavía.
