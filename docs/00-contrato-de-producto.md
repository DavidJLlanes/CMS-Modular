# Contrato de producto

## Alcance

CMS de uso general, un sitio por instancia, autohospedable en VPS con Ubuntu y Nginx.

- **Dentro:** páginas, entradas, categorías, etiquetas, menús, galerías, biblioteca multimedia WebP, editor de bloques, diseños por carpeta, SEO técnico, branding/PWA, plugins (mapa), IA opcional, formularios opcionales, exportación y copias.
- **Fuera (salvo cambio de alcance):** multi-sitio por instancia, paquetes nativos, galerías privadas con autenticación pública, comentarios, comercio electrónico.

## Invariantes

1. La web pública son archivos estáticos; no depende de PHP, SQLite ni del generador.
2. Originales, base de datos, secretos, copias y temporales viven fuera de la raíz web (`var/`).
3. Las funciones dinámicas (formularios) tienen ruta propia y aislada.
4. Una publicación se activa de forma atómica y se puede revertir.
5. Toda dependencia está justificada y registrada (ADR-0005).

## Requisitos y trazabilidad

| ID | Requisito | Fases | Módulo previsto | Verificación |
|---|---|---|---|---|
| R01 | Publicación estática de páginas, entradas, archivos y galerías | 1, 3, 4 | `src/Publishing/` | URLs públicas responden con panel y generador detenidos |
| R02 | Modularidad, código mantenible, responsabilidades separadas | 5, 11, 15 | `src/Core/`, `src/Extensions/`, `components/`, `plugins/` | Añadir diseño y plugin de ejemplo sin tocar el núcleo; revisión de contratos y dependencias |
| R03 | Sin dependencias pesadas, panel incluido | todas (continuo), 14 | transversal | Inventario directo/transitivo con tamaño, licencia y justificación; presupuestos medidos |
| R04 | Originales privados; imágenes de contenido en WebP con calidad configurable | 6 | `src/Media/` | Inspección del exportador, MIME y HTML; cambio de calidad y regeneración |
| R05 | Galerías variadas con recortes, efectos, lightbox y disuasión opcional | 8 | `components/galerias/` | Comparación móvil/escritorio, teclado, movimiento reducido; solicitudes iniciales y al abrir visor |
| R06 | SEO técnico completo con validación previa | 9 | `src/Seo/` | Matriz de URLs, estados HTTP, metadatos, sitemap, redirecciones, datos estructurados |
| R07 | Diseño personalizable, fuentes locales, identidad visual y PWA | 5, 10 | `components/`, `src/Publishing/` | Vista previa de componentes, fuentes usadas, iconos, instalación en plataformas objetivo |
| R08 | Editor completo con bloques, tipografía, columnas y tablas | 7 | `assets/admin/editor/` | Crear, guardar, reabrir y publicar la misma página en móvil y escritorio |
| R09 | Plugins activables e IA opcional multiproveedor | 11, 12 | `src/Extensions/`, `plugins/` | Instalar/desactivar mapa e IA; exclusión de recursos, preservación de datos, protección de claves |
| R10 | Seguridad por diseño, publicación reversible, recuperación demostrada | 2, 4, 13, 14 | `src/Security/`, `src/Publishing/` | Modelo de amenazas, pruebas de seguridad, simulacros de fallo, reversión y restauración |
| R11 | VPS económico, exportación y portabilidad | 0, 13, 14 | `deploy/`, `src/Publishing/` | Medición con catálogo representativo; restauración en entorno limpio; export en servidor estático |

| R12 | Compatibilidad con hosting compartido barato (añadido por el propietario, 2026-11-15) | 1, 4, 6, 9, 13, 14 | `src/Core/`, `src/Publishing/`, `src/Media/`, `deploy/` | Instalación y publicación en perfil B (Apache, PHP 8.1, 128 MB, 30 s, sin root); matriz de pruebas por perfil. Ver [ADR-0007](decisiones/0007-compatibilidad-hosting-compartido.md) |

**Aclaración R04/R07.** WebP cubre fotos, logos visibles y gráficos del tema. Favicon e icono de app se suben aparte y se generan en los formatos que exija la compatibilidad comprobada (PNG, ICO si se justifica). La tarjeta social: WebP por defecto, con comprobación por consumidor; cualquier excepción se somete a decisión.

## Mapa de fases

| Fase | Tema | Depende de |
|---|---|---|
| 0 | Descubrimiento, medición y decisiones | — |
| 1 | Esqueleto, instalación, separación público/panel | 0 |
| 2 | Identidad y seguridad del panel | 1 |
| 3 | Contenido, navegación, flujo editorial | 2 |
| 4 | Motor de publicación, coherencia, reversión | 3 |
| 5 | Contrato de diseños y personalización | 4 |
| 6 | Biblioteca multimedia y canal WebP | 3 |
| 7 | Editor de bloques | 3, 6 |
| 8 | Galerías, visor y efectos | 5, 6, 7 |
| 9 | SEO, accesibilidad, control de publicación | 4, 5 |
| 10 | Branding, favicon, PWA | 5, 6 |
| 11 | Extensiones y plugin de mapa | 5, 7 |
| 12 | IA editorial y de diseño | 2, 11 |
| 13 | Formularios, portabilidad, copias | 4, 6 |
| 14 | Rendimiento, seguridad, validación de lanzamiento | todas |
| 15 | Instalación final, documentación, evolución | todas |

Las fases 6 y 3 pueden avanzar en paralelo tras la 2.

## Estado de las fases

| Fase | Estado |
|---|---|
| 0 | Completada (pendiente inventario del VPS real) |
| 1 | Completada en perfil VPS ([fase-1.md](fase-1.md)) |
| 2 | Completada en perfil VPS ([fase-2.md](fase-2.md)) |
| 3 | Completada en perfil VPS ([fase-3.md](fase-3.md)) |
| 4 | Completada en perfil VPS ([fase-4.md](fase-4.md)) |
| 5 | Completada en perfil VPS ([fase-5.md](fase-5.md)) |
| 6 | Implementada y probada en sandbox; verificación en el VPS pendiente ([fase-6.md](fase-6.md)) |
| 7 | Implementada y probada en sandbox y en Chrome sin cabeza; verificación en el VPS y en Safari/móvil real pendiente ([fase-7.md](fase-7.md)) |
| 8 | Implementada y probada en sandbox y en Chrome sin cabeza; verificación en el VPS y en móvil real pendiente ([fase-8.md](fase-8.md)) |

## Criterio de cierre de la Fase 0

- [x] Contrato de producto (este documento)
- [x] Diagrama de despliegue y flujos ([02](02-despliegue-y-flujos.md))
- [x] Decisiones de arquitectura ([decisiones/](decisiones/))
- [x] Modelo de amenazas inicial ([03](03-modelo-de-amenazas.md))
- [x] Protocolo de medición ([04](04-protocolo-de-medicion.md))
- [x] Cada requisito apunta a fase y verificación (tabla anterior)
- [ ] Inventario real del VPS objetivo (pendiente de datos del propietario, ver [06](06-decisiones-pendientes.md))
