# Mapa del repositorio

Las carpetas se crean cuando exista una responsabilidad real. Esta es la estructura objetivo; en la Fase 0 solo existe `docs/`.

| Ruta | Responsabilidad | Fase |
|---|---|---|
| `src/Core/` | Configuración, contratos estables, registro de módulos, arranque mínimo | 1 |
| `src/Content/` | Modelo editorial, tipos, versiones, slugs, validación | 3 |
| `src/Security/` | Autenticación, sesiones, permisos, controles compartidos | 2 |
| `src/Admin/` | Rutas, controladores, vistas y acciones del panel | 2 |
| `src/Publishing/` | Planificación, dependencias, compilación, preflight, versiones, reversión | 4 |
| `src/Media/` | Ingesta, biblioteca, recetas de derivados, colas, caché WebP | 6 |
| `src/Gallery/` | Galerías: almacén validado, renderizador de diseños de galería, recortes con foco | 8 |
| `src/Seo/` | Metadatos, canonicals, indexación, sitemaps, validaciones | 9 |
| `src/Extensions/` | Descubrimiento, validación, registro, ciclo de vida | 11 |
| `assets/admin/` | CSS y JS del panel, un módulo por función | 2 |
| `components/` | Un directorio por tipo y diseño (`components/cabeceras/cabecera-corporativa/`) | 5 |
| `plugins/` | Un directorio por plugin: manifiesto, ajustes, código, recursos | 11 |
| `public/admin/` | Entrada pública mínima del panel (puede tener host propio) | 1 |
| `var/` | SQLite, originales, secretos por referencia, logs, trabajos, temporales. **Fuera de la raíz web y de Git** | 1 |
| `deploy/` | Ejemplos de Nginx, servicios, permisos, actualizaciones, respaldo | 1 |
| `docs/`, `tests/` | Especificaciones, decisiones, manuales; pruebas por dominio | 0+ |
| `releases/`, `current` | Versiones generadas y puntero activo. Directorio operativo **separado del código** | 4 |

## Reglas

- Cada función nueva va en su área con contratos, implementación, vistas y recursos propios.
- Interfaces pequeñas, dependencias explícitas. Modelo de contenido y generador ejecutables por CLI. Las vistas reciben datos ya preparados.
- Referencia de 250–300 líneas por archivo de lógica; por encima de ~400 se revisa y se justifica. Excepciones: archivos declarativos o generados.
- Contratos con esquemas versionados y ejemplos ejecutables (contenido, configuración, extensiones, exportación); migraciones y ventana de compatibilidad documentadas.
- Commits pequeños; cada fase entrega documentación, cambios reproducibles y evidencias.
- Datos de ejemplo y pruebas siempre sintéticos.

## Convenciones iniciales

- Idioma de documentación y mensajes al operador: español. Identificadores de código: inglés.
- Rutas de contenido en minúsculas ASCII, guiones; claves internas estables separadas de las rutas públicas.
- Namespace PHP raíz: `LuxAeterna\` mapeado a `src/` (PSR-4, autoloader propio mínimo; sin Composer obligatorio en producción).
