# ADR-0012 · Contrato de diseños por carpeta, tema y tipografía
Estado: Aceptada
Fecha: 2026-11-15

## Contexto
La Fase 5 permite cambiar cabeceras, barras, heroes y pies copiando una carpeta, personalizar colores y tipografía y no depender de terceros en la web publicada. Un diseño es contenido de terceros potencial: no puede ejecutar código de servidor ni cargar nada externo (R01, R03, R04).

## Decisión
- **Una carpeta por diseño**: `components/<familia>/<carpeta>/` con `manifest.json` (schema 1), `template.html`, `style.css`, `script.js` opcional y `preview.webp` opcional. El identificador (`id`) es único en todo el sistema; la familia es solo organización.
- **Contrato**: `id`, `type` (`header|topbar|hero|footer`), `name`, `version` (semver), `requires.lux` (versión mínima), `fields` (tipos `text|textarea|bool|select|number|color`, con etiqueta, límites y valor por defecto obligatorio), `resources` declarados (`fonts`, `images`; nunca URL). Solo se admiten nombres de archivo sin ruta, ≤ 256 KB, sin enlaces simbólicos.
- **Plantillas de capacidades limitadas**: marcadores `{{ x }}` (escapados) con campos propios y variables estándar (`site_name`, `home_url`, `title`, `description`, `year`) y `{{{ nav }}}`, `{{{ footer_nav }}}`, `{{{ logo }}}` como únicos HTML preparado por el generador. Sin lógica, sin PHP. Se rechazan `<script>`, `<iframe>`, `<form>`, `<meta>`, `style=`, `on*=`, `javascript:` y recursos remotos. Un hero debe incluir el `<h1>`.
- **CSS y JS**: sin `@import`, `url()` remotas ni `expression()`. El JS de un diseño no puede usar `eval`, `Function`, importación dinámica, `fetch`, `XMLHttpRequest`, `WebSocket` ni contener direcciones remotas; se publica como archivo (CSP `script-src 'self'`). La ejecución de código de servidor queda reservada a extensiones instaladas por el operador (plugins, fase posterior).
- **Índice cacheado**: `var/cache/designs.json`, con huella de stat de todos los archivos de los diseños. Cambiar cualquier archivo, añadir o quitar una carpeta invalida la caché; «Detectar diseños de nuevo» (panel) y `bin/lux designs:refresh` fuerzan la reconstrucción. Un diseño inválido se lista con su motivo y no impide el resto; si hay `id` duplicados se ignoran ambos.
- **Precedencia de ajustes**: global → tipo de contenido → página → instancia. Cada nivel puede elegir otro diseño (reinicia los valores heredados), apagar la ranura o solo cambiar valores. Si un diseño elegido desaparece, la ranura se omite y se avisa; el sitio sigue publicándose. Los ajustes viajan dentro del `SiteSnapshot` (misma instantánea coherente que el contenido).
- **Valores por instancia sin estilos en línea**: los campos `color` y `number` se traducen a variables CSS (`--lux-f-<campo>`) en una clase generada `lux-i-<hash>` reutilizable, dentro del CSS núcleo. No hay `style=` en la web ni en el panel.
- **Tema**: colores por rol (`bg, surface, text, muted, primary, primary_text, link, border`) y por componente (cabecera, barra, hero, pie), cuatro paletas AA de serie, auditoría de contraste WCAG (≥ 4,5:1) mostrada en el panel con muestras (hoja `swatch.css` validada, porque el panel no admite estilos en línea). Los diseños consumen variables, nunca colores propios.
- **Tipografía**: familia, tamaño (14–22 px), interlineado, alineación, espaciado y ancho de contenido, como variables CSS.
- **Fuentes locales**: catálogo `components/fonts/<slug>/` con `font.json`, WOFF2 y archivo de licencia (OFL-1.1, Apache-2.0, UFL-1.0). Solo se publican las variantes realmente usadas por el tema (`/assets/fonts/<slug>-<peso>-<estilo>.<hash>.woff2`), con `font-display: swap`, `unicode-range` y precarga de la variante principal; si falta un peso se usa el más cercano. Pila de reserva por categoría. Se comprueba la cobertura de caracteres del español y de los idiomas que se habiliten. `bin/lux fonts:add` importa una familia de Google Fonts (lo ejecuta el operador con red; el operador declara la licencia). Se incluyen Inter, Lora y Source Serif 4 (OFL-1.1).
- **Logo**: texto o imagen de `components/logos/` (PNG, WebP, JPEG, SVG sin scripts ni referencias externas, ≤ 512 KB). El tamaño se fija solo como ancho por ubicación (barra, cabecera, hero, pie) y dispositivo; el alto sale de la proporción real, así que no puede deformarse. El móvil no puede superar al escritorio.
- **Recursos y huellas**: un CSS núcleo (`/assets/core.<hash>.css`: fuentes, tema, base, instancias) y un CSS/JS por componente (`/assets/c/<id>.<hash>.css|js`), enlazados solo en las páginas donde el componente interviene y compartidos sin duplicar. Se retienen los de las versiones recientes (ADR-0011).
- **Inventario**: `.lux-inventory.json` en cada versión (por página: componentes y archivos; módulos; compartidos; fuentes; logo), visible en el panel y con `bin/lux resources`.
- **Sin dependencias remotas**: la comprobación previa bloquea cualquier `<link>`, `<script>`, `<img>`, `<iframe>`, `<source>`… externo y todo `@import`/`url()` remoto en CSS (los `canonical`, `alternate` y los enlaces `<a>` externos son legítimos).

## Alternativas evaluadas
- Estilos en línea para valores por instancia: incompatible con CSP estricta.
- CSS por página: rompe el caché y duplica reglas; se optó por archivos por componente.
- Fuentes desde la CDN de Google: peticiones a terceros y huella de privacidad. Descartado (R04).
- Plantillas con lógica (Twig, etc.): amplía la superficie de ataque (ADR-0002).

## Consecuencias
- Un diseño de terceros no puede llegar a ejecutar código ni a filtrar datos por la web; el peor caso es que su contrato sea rechazado.
- Subir diseños por el panel (con revisión) y plugins se tratan en fases posteriores; hoy el operador los copia a `components/` en el servidor.
