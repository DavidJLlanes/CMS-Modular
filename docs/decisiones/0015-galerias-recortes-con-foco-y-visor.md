# ADR-0015 · Galerías: diseños por carpeta, recortes con foco y visor sin dependencias
Estado: Aceptada
Fecha: 2026-11-15

## Contexto
La Fase 8 añade galerías fotográficas con varios diseños, visor a pantalla completa y efectos, sin romper R03 (sin dependencias de terceros), R04 (WebP, originales privados), R05 (galerías), R12 (alojamiento barato) ni la CSP. Hay que decidir dónde vive el contenido de una galería, cómo se extienden los diseños, cómo se recortan las miniaturas y qué hace el visor sin JavaScript.

## Decisión
- **La galería es una entidad propia** (`galleries` + `gallery_items`), no un bloque con las fotos dentro. Los contenidos solo guardan `{type:'gallery', ref:'gal-…'}`: una galería se reutiliza en varias páginas y se reordena o amplía sin tocar cada revisión. La publicación congela la galería en el `SiteSnapshot` (como hace con las imágenes), de modo que un trabajo en segundo plano publica lo mismo que validó el preflight.
- **Diseños por carpeta, mismo contrato.** `type: gallery` añade `item` (plantilla de cada foto) y un objeto `gallery` con los recortes admitidos, el recorte por defecto, el ancho en pantalla de la miniatura (`thumb_px`) y `sizes`. Las plantillas solo reciben fragmentos ya preparados y escapados (`items`, `img`, `a_open`, `a_close`, `caption`…), de modo que un diseño no puede construir enlaces ni scripts propios. `type: viewer` es CSS + JS sin plantilla. Las opciones propias del diseño (columnas, espacio…) usan el esquema de campos existente y se exponen como variables CSS por clase de instancia.
- **Recortes en servidor con foco.** Los recortes (`square`, `landscape`, `portrait`) son derivados WebP `cover` centrados en el foco de cada foto (el de la biblioteca o uno propio de la galería); `free` conserva la proporción. La receta viaja en el nombre del archivo (`<uid>-<ancho>x<alto>f<fx>-<fy>.<clave>.webp`) y la clave sigue siendo la huella del original más la receta, así que cambiar un foco regenera solo esa foto y la previsualización puede validar nombre y clave sin consultar nada más.
- **Pocos derivados por foto.** Miniatura: anchuras de la escalera hasta el doble del ancho en pantalla (2–3). Visor: `1200` y el máximo configurado, deduplicados contra los de la miniatura (≤ 4 archivos por foto en la práctica).
- **Visor con mejora progresiva.** Cada foto es un enlace real a la imagen grande; sin JavaScript (o con Ctrl/⌘+clic) el navegador la abre tal cual. El visor (`components/visores/lightbox`) es un diálogo modal con foco atrapado, Escape, flechas, gestos táctiles, contador, pies y precarga de la siguiente, tres transiciones por CSS que respetan `prefers-reduced-motion`. Lee los datos de atributos `data-*` y construye el DOM con `createElement`/`textContent`.
- **Recursos solo donde se usan.** El ensamblador añade al `used` de cada página los diseños de galería y el visor únicamente si esa página tiene una galería (y, para el visor, con el visor activado): una página sin galería no carga nada de ello.
- **Disuasión honesta.** «Impedir arrastrar» y «sin menú contextual» son casillas opcionales por galería, descritas como molestia y no como protección; los originales nunca se publican.
- **CSP pública.** `script-src 'self'` se añade a la política del sitio público (antes `default-src 'none'` impedía cualquier script). Sin `unsafe-inline` ni orígenes externos.

## Alternativas evaluadas
- Fotos dentro del bloque de contenido: duplica datos, impide reutilizar y complica el modelo de la Fase 7.
- Recortes con CSS (`object-fit`) sobre el original reducido: descarga más bytes y el foco no se respeta en el dispositivo.
- Librería de galería/lightbox de terceros (PhotoSwipe, Fancybox, GLightbox): dependencia y peso, y no encajan en el contrato de diseños ni en la CSP.
- Masonry/justificado calculado con JavaScript: salta la maquetación (CLS) y rompe sin JS; se usaron columnas CSS y `flex`/`grid`.
- Visor basado en `<dialog>` nativo: buen soporte actual, pero la gestión del foco y de los gestos en navegadores móviles antiguos es dispar; se mantiene un diálogo ARIA propio y se revisará.

## Consecuencias
- Un diseño de galería nuevo es una carpeta (sin tocar código) y pasa el mismo control de contrato que los demás.
- La publicación inicial de una galería grande es costosa (≈ 1 s por foto de 3–6 MP en el entorno de referencia, ver `docs/fase-8.md`); las siguientes reutilizan los derivados con la misma huella.
- La estimación de peso del panel es orientativa (±2×), por lo que se presenta como tal.
- El visor no incluye zoom con pellizco propio (se deja al navegador) ni modo presentación.

## Verificación / revisión
`tests/GalleryTest.php`, `tests/js/browser/lightbox.html` (Chrome sin cabeza bajo CSP), `tests/nginx-smoke.sh`, `tests/bench-gallery.php`. Revisar el visor con `<dialog>` cuando se descarten navegadores antiguos y añadir zoom si hay demanda.
