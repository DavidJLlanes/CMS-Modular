# ADR-0004 · Procesado de imágenes y WebP

Estado: Provisional (decisión final en Fase 6, con mediciones)
Fecha: 2026-11-15

## Contexto
R04 exige originales privados y todas las imágenes de contenido en WebP con calidad configurable, con originales de hasta decenas de megapíxeles en un VPS de 1 GB.

## Decisión provisional
- Los originales se guardan en `var/` con identificador generado; nunca se sirven.
- Los derivados se definen por **receta** (dimensiones, recorte, foco, calidad, versión de procesado) y se identifican por **huella del original + receta**; no se regeneran si ambas siguen vigentes.
- Un único trabajador de cola, con límites de memoria, píxeles y tiempo.
- Candidatas: **GD** (incluido con PHP, ligero) y **Imagick/libvips** (mejor gestión de memoria y perfiles de color). La elección se fundamenta en la Fase 6 con un lote de 500 fotos en el entorno de referencia: RAM pico, tiempo por foto, fidelidad cromática y soporte de orientación/perfiles ICC.
- Metadatos privados (EXIF/GPS) se eliminan tras aplicar la orientación; el GPS solo se publica por decisión explícita (plugin de mapa).
- Si el VPS no aguanta lotes grandes, se evalúa procesar en un PC y transferir resultados con flujo documentado.

## Alternativas evaluadas
- Servicio externo de imágenes: rompe autonomía y privacidad.
- Servir originales con redimensionado al vuelo: necesita proceso dinámico en la ruta pública.

## Consecuencias
Hasta la Fase 6 no se instalan extensiones de imagen más allá de las que traiga PHP; el diagnóstico de la Fase 1 solo informa de cuáles hay.

## Verificación / revisión
Tabla comparativa de la Fase 6 y prueba de cambio de calidad con regeneración desde el original.

## Enmienda 2026-11-15 (ADR-0007)
- GD con WebP es el mínimo exigido; Imagick/libvips son mejoras opcionales detectadas por el diagnóstico.
- El procesado se hace en **lotes reanudables** que respetan `memory_limit` y `max_execution_time` (pico objetivo ≤ 96 MB por foto en el perfil B). Una foto que no cabe en memoria se rechaza con mensaje claro y se ofrece procesarla en un PC y subir el derivado.
