# Fase 8 · Galerías fotográficas, visor y efectos

## Qué hay
- **Galerías** (`/admin/galleries`): se crean con un título y un diseño; se les añaden fotos de la biblioteca (búsqueda y selección múltiple), se ordenan (↑ ↓ o número «Orden»), y cada foto admite **texto alternativo**, **pie**, **foco** (pulsando sobre la miniatura) y **recorte** propios; si se dejan vacíos se usan los de la biblioteca. Una galería se reutiliza en varias páginas y hasta 500 fotos.
- **Seis diseños** (carpetas en `components/galerias/`): `rejilla` (columnas iguales, recortes cuadrado/apaisado/vertical/libre), `mamposteria` (columnas con la proporción original), `mosaico` (fotos horizontales y verticales más grandes), `filas` (filas de altura fija), `carrusel` (tira con desplazamiento táctil y por teclado, sin JavaScript) y `destacada` (primera foto grande). Cada uno tiene opciones propias (columnas, espacio, alto de fila, ancho…) y vista previa en el panel de Diseño.
- **Recorte con foco**: las miniaturas recortadas se generan en servidor como WebP centrados en el foco de cada foto, con `srcset`, `sizes`, `width`/`height` y carga diferida. Cambiar un foco regenera solo esa foto.
- **Visor** (`components/visores/lightbox`, activable por galería): pantalla completa accesible (diálogo modal, foco atrapado, Escape, flechas, gestos táctiles), contador, pies de foto, precarga de la siguiente, flechas opcionales, bucle opcional y tres transiciones (fundido, deslizar, zoom) que respetan «reducir movimiento». Sin JavaScript, o con Ctrl/⌘+clic, cada foto abre la imagen grande en el navegador.
- **Disuasión opcional** por galería: impedir arrastrar y desactivar el menú contextual sobre las fotos. Es una molestia, no una protección; los originales nunca se publican.
- **Editor**: bloque «Galería» (elegir una existente, ver título y número de fotos, enlace para editarla) y aviso de SEO si no se ha elegido ninguna.
- **Publicación**: la galería se congela con el resto del contenido; los diseños de galería y el visor se enlazan **solo en las páginas que los usan**, con huella y caché inmutable; el inventario de recursos los lista. Avisos (no bloquean): galería vacía, fotos sin texto alternativo. Errores claros (bloquean): galería o foto que ya no existe.
- **Peso estimado** en la ficha de la galería: lo que descarga un visitante al verla y el total de archivos publicados.
- **Seguridad**: una foto que está en una galería o una galería usada en un contenido no se pueden borrar; las plantillas de galería pasan por el contrato de diseños (sin scripts, estilos en línea ni recursos remotos); textos escapados; el visor usa `textContent`. La **CSP pública pasa a incluir `script-src 'self'`** (antes bloqueaba todo script): actualiza con el comando de abajo para que se recargue Nginx.

## Contrato de diseños (cambios respecto a la Fase 5)
| Campo | Aplica a | Descripción |
|---|---|---|
| `type: gallery` | galerías | Necesita `template` (carpeta de la galería), `item` (cada foto), `css` y el objeto `gallery` |
| `gallery.crops` | galerías | Recortes admitidos: `free`, `square`, `landscape` (3:2), `portrait` (4:5) |
| `gallery.crop_default` | galerías | Recorte por defecto (uno de los admitidos) |
| `gallery.thumb_px` | galerías | Ancho en pantalla de la miniatura (120–1600): decide las anchuras a generar |
| `gallery.sizes` | galerías | Valor de `sizes` de la miniatura |
| `type: viewer` | visores | Solo `css` y `js` (sin plantilla); hoy se usa `lightbox` |
| Marcadores de la galería | `template` | `{{{ items }}}`, `{{ title }}`, `{{ count }}` y los campos propios |
| Marcadores de cada foto | `item` | `{{{ a_open }}}`, `{{{ a_close }}}`, `{{{ img }}}`, `{{{ caption }}}`, `{{ index }}`, `{{ alt }}`, `{{ cls }}` (`is-wide`/`is-tall`/`is-square`), `{{ title }}` y los campos propios |
`requires.lux` de estos diseños: 0.7.0. El JS de un diseño ya no se rechaza por la palabra `function`, solo por `eval`, `new Function(`, red y direcciones remotas.

## Medición (entorno de referencia, GD, fotos sintéticas con ruido = peor caso)
| Galería | Primera publicación | Derivados | Miniaturas visibles | HTML | JS+CSS | RAM pico |
|---|---|---|---|---|---|---|
| 24 fotos de 6 MP, `rejilla` cuadrada | 25 s (≈ 1 s/foto) | 96 · 23,7 MB | 1,4 MB (58 KB/foto) | 16 KB | 10 KB | 86 MB |
| 60 fotos de 3 MP, `mosaico` | 51 s (≈ 0,85 s/foto) | 240 · 45,8 MB | 8,4 MB (143 KB/foto) | 38 KB | 11 KB | 72 MB |
- Las fotos reales comprimen bastante mejor que el ruido sintético; el panel estima el peso con 0,0021 B/píxel × calidad (≈ 0,17 B/píxel a calidad 82), entre ½ y 1× de lo medido aquí. Es orientativo.
- Las publicaciones siguientes reutilizan los derivados con la misma huella (solo se procesa lo nuevo o modificado). Con 60 fotos la primera publicación dura casi un minuto: va en el trabajo de segundo plano, no en la petición web.
- `php tests/bench-gallery.php [fotos] [megapíxeles] [diseño]` reproduce la medición.

## Pruebas
- `php tests/run.php galer` (contrato, almacén, publicación, vista previa, panel).
- `bash tests/js/browser/run.sh` (ahora también `lightbox.html`: apertura, foco, flechas, bucle, gestos, Escape, Tab atrapado, disuasión, CSP sin violaciones; y el bloque de galería del editor).
- `bash tests/nginx-smoke.sh` (estáticos del panel de galerías y CSP pública con `script-src 'self'`).

## Actualizar el servidor
```
git -C /var/www/tu-dominio.es/app pull -q && bash /var/www/tu-dominio.es/app/deploy/install-vps.sh --domain=tu-dominio.es --base=/var/www/tu-dominio.es
```

## Qué probar a mano
1. Panel → «Galerías de fotos» → crea una galería con `rejilla`, añade 6–8 fotos, marca el foco de una foto de retrato y guarda: comprueba la miniatura recortada tras publicar.
2. En una página, bloque «Galería», elige la galería, guarda, publica y abre el sitio: el visor debe abrir con un toque, deslizar con el dedo, cerrar con la X y volver al sitio sin perder la posición.
3. Cambia el diseño a `mosaico`, `filas`, `carrusel` y `destacada`: la galería debe verse bien en móvil (sin desbordes).
4. Prueba el visor con teclado (Tab, flechas, Escape) y con un lector de pantalla si puedes.
5. Intenta borrar una foto que está en una galería, y una galería usada: debe negarse con un mensaje.
6. Mira el HTML publicado: ninguna página sin galería debe enlazar `/assets/c/lightbox…`.

## Límites conocidos
- Safari/iOS y Android reales sin probar aquí (las pruebas automáticas usan Chrome); revisa el visor con gestos y la barra de direcciones móvil.
- El visor no tiene zoom propio (se deja al pellizco del navegador), modo presentación, ni compartir.
- La selección de visor es global (`lightbox`); elegir otro visor por galería queda para cuando exista más de uno.
- Sin arrastrar y soltar para ordenar fotos (↑ ↓ y número de orden).
- La estimación de peso es aproximada. Una galería no puede ir dentro de columnas (solo en el primer nivel); se puede poner más de una galería por página.
