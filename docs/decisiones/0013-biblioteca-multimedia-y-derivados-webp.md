# ADR-0013 · Biblioteca multimedia y derivados WebP con GD
Estado: Aceptada
Fecha: 2026-11-15

## Contexto
La Fase 6 añade imágenes al contenido. Las fotos suben desde el panel (usuarios de confianza, pero entradas no confiables), deben publicarse optimizadas (R04), sin dependencias nuevas (R03) y en hosting barato (R12). Los originales no pueden quedar expuestos.

## Decisión
- **Procesador: GD con WebP** (ya presente en la mayoría de PHP, incluido el compartido). Imagick queda como mejora opcional futura (conversión de perfiles ICC). Sin binarios externos ni AVIF.
- **Originales privados** en `var/media/originals/<aa>/<id>.<ext>` (fuera de la raíz web), identificador generado, inmutables. Metadatos editoriales en SQLite (`media`); derivados registrados en `media_derivs`.
- **Validación por contenido** antes de guardar: `getimagesize` + `finfo`, límites de bytes, lado y píxeles, estimación de memoria, rechazo de animados y de archivos que no decodifican (se decodifica y se genera la miniatura en la propia subida; si falla se deshace).
- **Recetas con huella**: `Recipe::key = sha256(huella original | receta)`. Receta = ancho, alto, ajuste (`inside`/`cover`), foco, calidad y `VERSION` del procesado (súbela al cambiar el algoritmo). Un derivado vigente nunca se regenera; cambiar calidad o reemplazar el original genera claves nuevas.
- **Solo lo necesario**: las anchuras salen de la escalera `400/800/1200/1600/2400/3200` limitada por el original, `media.max_width` y el doble del ancho de contenido del tema; nunca se amplía.
- **Un trabajador**: `flock` en `var/media/.lock` y una sola decodificación por imagen y tanda. El procesado corre dentro del trabajo de publicación (estados, reintentos, cancelación y diagnóstico de ADR-0011) y se puede adelantar con `media:warm`.
- **Publicación**: la versión contiene solo `/media/<id>-<ancho>.<clave>.webp` (enlace duro o copia), más `.lux-media.json`. Preflight exige que cada archivo de `/media/` sea WebP, que los `src`/`srcset` existan y avisa de imágenes sin `alt`. Se arrastran los derivados de las versiones retenidas como los recursos del tema.
- **Orientación antes de metadatos**: se aplica EXIF 1–8 y se recodifica, descartando EXIF/GPS/comentarios. Perfiles no sRGB: aviso (GD no convierte).
- **Borrado seguro**: una imagen usada por una revisión en edición o publicada no se borra; la limpieza de derivados respeta las versiones retenidas y un periodo de gracia.
- **Calidad**: ajuste de panel (40–95, defecto 82), interpretado como `quality` de libwebp.
- **Previsualización**: rutas privadas `/admin/preview/media/<id>-<ancho>.<clave>.webp`; el servidor recalcula la receta y solo sirve claves que coinciden (no se pueden pedir recetas arbitrarias ni agotar CPU).

## Alternativas evaluadas
- Imagick/libvips: mejor gestión de color y rendimiento, pero no está en todos los hostings compartidos (R12) y añade dependencia; se deja como opción.
- Generar derivados al subir (todas las anchuras): desperdicia CPU y disco en tamaños que nadie usa; se prefiere bajo demanda y por uso real.
- Cola propia de imágenes: duplicaría estados y recuperación ya resueltos en la cola de publicación.
- Servir derivados dinámicamente desde PHP: rompe la publicación estática (R01).

## Consecuencias
- Publicar la primera vez con muchas fotos tarda (≈1,6 s por foto de 12 MP): usar `media:warm` o la CLI.
- Colores de fotos con perfil ancho pueden cambiar (se avisa).
- `php-gd` pasa a ser requisito para usar imágenes (el doctor lo comprueba).
- Las galerías (Fase 8) reutilizan recetas con recorte y foco.

## Verificación / revisión
`MediaTest`, `MediaPanelTest`, `nginx-smoke.sh` (WebP real servido con caché inmutable, originales no accesibles) y `tests/bench-media.php`. Revisar tras medir en el VPS real.
