# ADR-0014 · Editor de bloques propio y modelo sin HTML libre
Estado: Aceptada
Fecha: 2026-11-15

## Contexto
La Fase 7 necesita edición rica (formato, columnas, tablas, imágenes, botones) sin romper R03 (sin dependencias de terceros), R04 (peso) ni la CSP del panel (sin scripts ni estilos en línea), y con un riesgo de XSS controlado por construcción. Los editores existentes (TinyMCE, Quill, TipTap, ProseMirror) añaden cientos de KB, dependencias o producen HTML libre.

## Decisión
- **Modelo estructurado, no HTML.** Esquema 2 de bloques (`Blocks`, `BlockValidator`, `Inline`): texto con tramos `{t, b, i, s, u, sub, sup, c, a}`, bloques tipados y atributos de lista blanca. Los textos sin formato conservan la forma simple de la Fase 3, de modo que la sintaxis de texto sigue funcionando para contenido sencillo.
- **Validación doble.** El navegador normaliza (`model.js`) y el servidor valida siempre (`BlockValidator`); lo que el servidor no reconoce se rechaza o se descarta.
- **Edición con `contenteditable`, pero nunca como fuente de verdad.** Cada campo editable se lee con un tokenizador propio (`html.js`) que solo conserva marcas permitidas; los estilos de Word/Google Docs (`font-weight`, `mso-*`, `<font color>`…) se traducen a marcas o se descartan. El HTML pegado se intercepta siempre.
- **Estilo por clases.** Alineación, tipografía y colores salen como clases (`lux-a-center`, `lux-t-size-lg`, `lux-c-primary`); los colores libres generan una regla `lux-c-<hex>` en la hoja principal a partir de los colores realmente usados. La tipografía son preajustes (tamaño, grosor, interlineado, espaciado, familia, espacio entre párrafos) que heredan de la plantilla.
- **Módulos JavaScript clásicos** (`model`, `html`, `history`, `lint`, `storage`, `blocks`, `inspector`, `media-picker`, `main`) cargados solo en la pantalla de edición; sin empaquetador y con UMD mínimo para probar la lógica pura con `node --test` y el conjunto completo en Chrome sin cabeza bajo la misma CSP.
- **Columnas y tablas accesibles.** Las columnas son una rejilla en el mismo orden de lectura que el DOM y se apilan en móvil; la tabla lleva `caption`, `th scope`, y una región desplazable con `tabindex` para tablas anchas.
- **Compatibilidad sin JavaScript.** El campo JSON no tiene `name` hasta que el editor arranca; el área de texto solo se ofrece cuando todo el contenido es simple; un cuerpo no enviado conserva el actual.
- **Extensión.** `BlockValidator::registerExtension(kind, validar, generar)` permite añadir bloques (mapas, etc.) sin tocar el núcleo; sin registro, el bloque se rechaza al guardar y bloquea la publicación si ya existía.
- **H1 permitido** (niveles 1–6) con aviso en el editor y en la publicación cuando la plantilla ya emite el título como H1.
- **Recuperación y autoguardado.** Historial por instantáneas, copia local ligada al contenido del servidor del que parte, y el autoguardado de la Fase 3 sin cambios (el campo JSON viaja en el mismo formulario).

## Alternativas evaluadas
- Editor de terceros (TipTap/ProseMirror/Quill/TinyMCE): peso, dependencias, HTML libre o esquemas propios que habría que sanear igualmente.
- Markdown con barra de herramientas: no cubre columnas, tablas con formato ni colores sin ampliar la sintaxis hasta algo propio.
- Guardar HTML saneado: obliga a un sanitizador HTML completo en servidor (superficie grande) y mezcla contenido con presentación.
- Edición con formularios por bloque sin texto enriquecido: fiable pero lenta y poco usable en móvil.

## Consecuencias
- Un modelo propio exige mantener a la par el validador PHP y el normalizador JS (hay pruebas en ambos lados con los mismos casos).
- `contenteditable` se comporta distinto entre navegadores (sobre todo Safari móvil e IME): se limita su papel a campos de una línea de bloque con formato en línea, y se prueban composición e Intro. La verificación en Safari/iOS y Android reales queda como paso manual.
- La clase de color por `#rrggbb` aumenta ligeramente la hoja principal según los colores usados.

## Verificación / revisión
`tests/EditorModelTest.php`, `tests/EditorPanelTest.php`, `tests/js/*.test.mjs`, `tests/js/browser/run.sh` (Chrome sin cabeza), `tests/nginx-smoke.sh`. Revisar cuando se añadan galerías (Fase 8) o bloques de extensión de terceros.
