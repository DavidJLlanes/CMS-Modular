# Fase 6 · Biblioteca multimedia y canal WebP

## Qué hay
- **Biblioteca** en el panel (`/admin/media`, roles admin y editor): subida de varias imágenes a la vez, búsqueda, ficha con nombre editorial, texto alternativo, pie, crédito y foco, aviso de dónde se usa cada imagen, reemplazo del archivo y borrado protegido.
- **Originales privados** en `var/media/originals/` con identificador generado (`img-` + 12 hex); el nombre subido solo sirve de título editorial. Nunca se modifican y nunca están bajo la raíz pública. Se descargan solo desde el panel (gestores de medios), como adjunto y sin caché.
- **Derivados WebP** generados con GD (sin dependencias nuevas, R03/R12). Cada derivado se identifica por la huella del original más la receta (anchura, alto, recorte, foco, calidad y versión de procesado): si ninguna cambia, no se regenera.
- **Publicación de imágenes**: bloque `[imagen:ID|texto alternativo|pie]` → `<figure><img srcset sizes width height alt loading decoding>`. Solo se generan las anchuras que el uso real necesita (ancho de contenido del tema × 2, límite configurable y nunca más que el original). La primera imagen entre los dos primeros bloques lleva `fetchpriority="high"` y no se difiere (candidata a LCP); las demás, `loading="lazy"`.
- **Calidad configurable** (panel, solo admin): 40–95, por defecto 82 (parámetro `quality` de libwebp, con pérdida). Cambiarla regenera desde los originales en la próxima publicación.
- **CLI**: `media`, `media:add`, `media:warm`, `media:check`, `media:gc`.

## Validación de entrada
Se mira el **contenido**, no la extensión ni el tipo declarado: formato real (JPEG, PNG, WebP, GIF), coherencia entre `finfo` y cabecera, tamaño máximo (12 MB), lado máximo (12 000 px), megapíxeles (24 MP) y memoria estimada frente a `memory_limit`. Se rechazan con un mensaje claro: archivos vacíos o truncados, formatos no soportados, animados (GIF con varios fotogramas, APNG, WebP animado), archivos que no decodifican. Al subir se decodifica y se genera la miniatura, de modo que una imagen manipulada no deja rastro. Un JPEG con código PHP añadido se acepta como imagen, pero el derivado es una recodificación limpia: el código no se conserva y el original no se sirve.

Límites en `config/lux.json` (sección `media`): `max_upload_mb`, `max_pixels`, `max_side`, `max_seconds`, `quality`, `max_width`.

## Orientación, metadatos y color
- La orientación EXIF (1–8) se aplica **antes** de recodificar (probado píxel a píxel) y las dimensiones guardadas son las de visualización.
- Al recodificar a WebP se eliminan EXIF, GPS y comentarios; el original conserva los suyos (por eso solo lo descargan quienes gestionan medios).
- **Perfiles de color**: GD no convierte perfiles ICC. La ingesta detecta un perfil incrustado que no sea sRGB (por ejemplo Display P3 o Adobe RGB) y lo avisa en la ficha («revisa que los colores sigan bien»): el derivado se trata como sRGB. Para fidelidad cromática estricta, exporta a sRGB antes de subir. Limitación documentada; una decisión posterior puede añadir Imagick opcional.
- **Transparencia**: PNG/WebP/GIF con alfa conservan el canal alfa en el WebP. **Animados**: rechazados explícitamente.

## Recetas, huellas y limpieza
- Clave = `sha256(huella del original | receta canónica)[0:16]`. El archivo publicado es `/media/<id>-<ancho>.<clave>.webp` (caché inmutable, 1 año).
- Reemplazar el archivo cambia la huella y por tanto todas las claves; el original anterior se borra y los derivados antiguos caducan a las 24 h.
- Cada versión incluye `.lux-media.json` (derivados que usa). `bin/lux media:gc` (y el botón del panel) borra solo derivados que **no** usa ninguna versión retenida **y** llevan más de 7 días sin pedirse. Las versiones publicadas son autosuficientes: la reversión no necesita regenerar nada, y los derivados de las dos versiones anteriores se arrastran a la nueva (como los recursos del tema) para no romper páginas en caché.
- La publicación idéntica se omite (ADR-0011): sin cambios de contenido, ajustes ni originales, no se escribe nada.

## Cola y trabajador
Un solo trabajador de imágenes (bloqueo `var/media/.lock`) construye los derivados que faltan decodificando el original una vez por imagen. Corre dentro del trabajo de publicación, que ya tiene estados, cancelación, reintentos con espera y diagnóstico (ADR-0011); no hay una segunda cola. Para lotes grandes, `bin/lux media:warm` prepara los derivados sin escribir versión y `bin/lux publish` no tiene límite de tiempo web. Procesar en un PC y transferir resultados no es necesario con las mediciones siguientes (revisar si crece el catálogo).

## Medición (GD 2.3.3, PHP 8.3, un núcleo; `php tests/bench-media.php`)
| Foto | Subida + miniatura | 4 derivados | RAM máxima del proceso |
|---|---|---|---|
| 12 MP (4242×2828, 1,5 MB) | 0,22 s | 1,3 s | 105 MB |
| 24 MP (6000×4000, 2,9 MB) | 0,41 s | 1,7 s | 152 MB |

≈1,6 s por foto de 12 MP y ≈2,2 s por foto de 24 MP; un lote de 100 fotos de 12 MP ≈ 3 min. Pesos: 4 derivados de una foto de 12 MP ≈ 1,0 MB frente a 1,5 MB del original. GD reserva memoria fuera del gestor de PHP, así que `memory_limit` no la acota siempre: la comprobación previa estima `ancho × alto × 5 + 24 MB` y rechaza lo que no cabe. El pool de PHP-FPM del VPS usa `memory_limit=256M` y `max_execution_time=120`. **Pendiente medir en el VPS real** (ver abajo).

## Despliegue
- Requiere `php-gd` con WebP y, recomendable, `exif` (`bin/lux doctor` lo comprueba).
- Nginx: `location /media/` inmutable y `client_max_body_size 16m` en `/admin/`; PHP-FPM: `post_max_size=16M`, `upload_max_filesize=14M`. Apache/LiteSpeed: la plantilla marca `.webp` como inmutable.
- Tras actualizar: `bin/lux doctor` y subir una imagen de prueba.

## Verificar en el VPS
```bash
runuser -u luxaeterna -- php /var/www/luxaeterna.es/app/bin/lux doctor
```
1. En `/admin/media` sube una foto grande; abre su ficha y comprueba la miniatura y «Descargar el original».
2. Pega en una página `[imagen:ID|texto alternativo|pie]` (el ID sale en la ficha), publica y mira el HTML: `srcset`, `sizes`, `width`, `height`.
3. `curl -sI https://luxaeterna.es/media/<archivo>.webp` → `image/webp` y `immutable`; `curl -s -o /dev/null -w '%{http_code}' https://luxaeterna.es/var/media/` → 404.
4. Cambia la calidad en `/admin/media`, publica y comprueba que aparecen archivos nuevos y el original no cambia: `bin/lux media:check`.
5. `bin/lux media:add foto.jpg` en el servidor imprime tiempo y RAM por lote.

## Límites conocidos
- Las galerías (bloque `[galeria:...]`) siguen sin publicarse: llegan en la Fase 8 con sus diseños, lightbox y recortes (las recetas con recorte y foco ya existen).
- Todavía no hay selector de imágenes en el editor (Fase 7): se pega el identificador.
- Logo e iconos del tema siguen saliendo de `components/logos/` (el favicon/PWA llega con la Fase de identidad visual).
- Perfiles ICC no se convierten (aviso); sin AVIF.
