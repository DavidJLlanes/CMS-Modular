# Fase 5 · Contrato de diseños por carpeta y personalización

## Qué hay
- **Diseños por carpeta** (`components/<familia>/<diseño>/`): cabecera, barra superior, hero y pie, con contrato validado y caché invalidada por huella.
- **Ejemplos**: `cabecera-basica` (por defecto), `cabecera-corporativa`, `barra-aviso`, `hero-simple`, `footer-basico` (por defecto) y `footer-centrado`.
- **Panel «Diseño»** (administradores): elegir diseño por ranura, valores de sus campos, diseño por tipo de contenido, paletas, colores por rol y componente con auditoría de contraste, tipografía, logo, catálogo de fuentes, inventario de recursos y botón «Detectar diseños de nuevo».
- **Precedencia** global → tipo → página → instancia. Los niveles página e instancia se editan con `bin/lux design:set` (el panel visual de página llega con el editor, Fase 7).
- **Fuentes locales** (Inter, Lora, Source Serif 4; OFL-1.1): se publican solo las variantes usadas, desde tu dominio.
- **Sin dependencias remotas**, comprobado en cada publicación.

## Contrato `manifest.json` (schema 1)
```json
{
  "schema": 1,
  "id": "cabecera-corporativa",
  "type": "header",
  "name": "Cabecera corporativa",
  "version": "1.0.0",
  "description": "Logo centrado con eslogan.",
  "requires": { "lux": "0.4.0" },
  "template": "template.html",
  "css": "style.css",
  "js": "script.js",
  "preview": "preview.webp",
  "fields": {
    "eslogan": { "type": "text", "label": "Eslogan", "max": 80, "default": "" },
    "fondo": { "type": "color", "label": "Color de fondo", "default": "#f4efe6" }
  },
  "resources": { "fonts": [], "images": [] }
}
```
- `type`: `header | topbar | hero | footer`. `js` y `preview` son opcionales.
- Campos: `text`, `textarea`, `bool`, `select` (con `options`), `number` (`min`, `max`), `color` (`#rrggbb`). El valor por defecto es obligatorio y válido.
- Marcadores de plantilla: los campos propios, `{{ site_name }}`, `{{ home_url }}`, `{{ title }}`, `{{ description }}`, `{{ year }}` (escapados) y `{{{ nav }}}`, `{{{ footer_nav }}}`, `{{{ logo }}}` (HTML preparado). Los campos `color` y `number` se exponen en el CSS como `var(--lux-f-<campo>)`.
- Variables del tema disponibles en el CSS: `--lux-bg|surface|text|muted|primary|primary-text|link|border`, `--lux-<ranura>-bg|text|link` (opcionales), `--lux-font-body|heading`, `--lux-width`, `--lux-gap`.
- Matriz de compatibilidad: `requires.lux` ≤ versión instalada (hoy 0.4.0). Un diseño que pida más se rechaza con un mensaje claro.
- Recursos permitidos: solo archivos de la propia carpeta y fuentes del catálogo local. Prohibido: URL remotas, `@import`, `<script>` en la plantilla, estilos en línea, `eval`/`fetch` en el JS.

## Crear un diseño (ejemplo mínimo)
```
cp -r components/cabeceras/cabecera-corporativa components/cabeceras/mi-cabecera   # en el servidor, como root
# edita manifest.json (id nuevo), template.html y style.css
runuser -u luxaeterna -- php /var/www/luxaeterna.es/app/bin/lux designs:refresh
```
Aparece en el panel > Diseño. Si algo no cumple el contrato, `designs` lo lista con el motivo.

## Comandos
| Comando | Qué hace |
|---|---|
| `bin/lux designs` / `designs:refresh` | Lista diseños y errores de contrato / reconstruye el índice |
| `bin/lux fonts` / `fonts:check` | Catálogo local, licencias y cobertura de caracteres |
| `bin/lux fonts:add "Familia" --license=OFL-1.1 [--weights=400,700] [--category=sans-serif]` | Importa una familia de Google Fonts al catálogo local (requiere red) |
| `bin/lux design:show` / `design:set ARCHIVO.json` | Ver o sustituir todos los ajustes (global, tipos, páginas, instancias, tema, logo) |
| `bin/lux resources [--path=/ruta/]` | Inventario de recursos de la versión activa |

Ejemplo de `design:set` con ajustes por página e instancia:
```json
{
  "global": { "header": { "id": "cabecera-corporativa", "values": { "eslogan": "Mi lema" } } },
  "types": { "post": { "hero": { "id": "hero-simple" } } },
  "pages": { "c_abc123": { "hero": { "off": true } } },
  "instances": { "c_abc123": { "header": { "values": { "fondo": "#112233" } } } },
  "theme": { "typography": { "body": { "family": "inter" } } }
}
```

## Actualizar el VPS
```
git -C /var/www/luxaeterna.es/app pull -q && bash /var/www/luxaeterna.es/app/deploy/install-vps.sh --domain=luxaeterna.es --base=/var/www/luxaeterna.es
```

## Qué se probó
- 137 pruebas automáticas (contrato, rechazos de seguridad, caché, precedencia, tema y contraste, fuentes, logo, publicación, panel, previsualización).
- Nginx real: CSS de componente y fuentes con huella, caché inmutable, MIME `font/woff2`, sin peticiones a terceros, inventario oculto.

## Límites conocidos
- Subir diseños desde el panel y plugins con código de servidor no están (los diseños se copian a `components/`).
- La biblioteca multimedia (Fase 6) sustituirá la carpeta `components/logos/` por un selector.
- Selección de diseño por página en la interfaz: Fase 7 (editor visual); hoy por `design:set`.
