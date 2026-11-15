# Fase 7 · Editor completo y ligero

## Qué hay
- **Editor de bloques propio** en la pantalla de edición (JavaScript sin dependencias, módulos en `public/admin/assets/editor/`). Barra fija con deshacer/rehacer, negrita, cursiva, subrayado, tachado, sub/superíndice, enlace, color y «quitar formato»; selector de bloque y botones táctiles de 44 px.
- **Bloques**: párrafo, títulos H1–H6, cita (con autor), listas (viñetas o numeradas), imagen, galería (reservada, Fase 8), botón, separador (línea, puntos, espacio), **columnas** (2, 3 y 4 con 6 distribuciones, separación y alineación vertical; en móvil se apilan en orden de lectura) y **tablas** (título, fila/columna de encabezados, alineación por columna, hasta 50×12, región desplazable con teclado) y un punto de extensión para mapas u otros.
- **Tipografía y color**: alineación (izquierda, centro, derecha, justificado), tamaño, grosor, cursiva, interlineado, espaciado de letras, tipo de letra (heredada, texto, títulos), espacio entre párrafos y color (del tema o libre). Se guardan como valores admitidos y salen como clases; nunca hay `style=` ni CSS libre.
- **Mover, duplicar, borrar**: botones, teclado (Alt+↑/↓, Alt+Mayús+D, Alt+Supr) y táctil. **Deshacer/rehacer** (Ctrl+Z / Ctrl+Y y gesto de iOS), **autoguardado** (el de la Fase 3) y **recuperación local** si se recarga o se cierra por error.
- **Pegado limpio**: Word, Google Docs, correo y web se reducen al formato permitido; las viñetas de Word pasan a listas reales; las tabulaciones de Excel, a tablas; imágenes, scripts, estilos y atributos se descartan.
- **Imágenes**: selector de la biblioteca con búsqueda y subida desde el propio editor, texto alternativo, pie y alineación, con avisos.
- **Revisión de SEO y accesibilidad** en el editor y avisos en la publicación: H1 que choca con el de la plantilla, saltos de nivel, títulos vacíos, imágenes sin `alt`, tablas sin encabezados, botones sin enlace válido.
- **Compatibilidad**: el contenido sencillo sigue pudiéndose editar como texto sin JavaScript; el contenido rico se conserva intacto si se guarda sin el editor.

## Seguridad y rendimiento
- Sin HTML en el modelo; validación doble (navegador y servidor); enlaces solo `/ruta`, `#ancla`, `http(s)`, `mailto`, `tel`; colores `#rrggbb` o roles; límites de tamaño y profundidad.
- CSP del panel sin cambios (sin scripts ni estilos en línea) y comprobada en Chrome. El editor no aparece en el sitio público (hay pruebas).
- Peso del editor: unos 75 KB de JS y 6 KB de CSS sin comprimir, solo en la pantalla de edición. CSS de contenido del sitio: ~3,4 KB más sin comprimir, con las clases de color por uso real.

## Pruebas
- `php tests/run.php editor` (modelo, generación, panel, endpoints, exclusión del editor del sitio público).
- `node --test tests/js/*.test.mjs` (modelo, pegado Word/Google Docs/correo/Excel, historial, avisos, recuperación).
- `bash tests/js/browser/run.sh` (Chrome sin cabeza bajo la CSP del panel: escritura, IME, Intro, mover, duplicar, deshacer, pegado, columnas, tablas, selector de imágenes y limpieza de borrador).
- `bash tests/nginx-smoke.sh` (estáticos del editor con tipo MIME correcto).

## Qué probar a mano
1. Edita una página, añade columnas, una tabla y un botón, guarda, recarga: todo igual (editar y reabrir sin perder formato).
2. Pega desde Word y desde un correo (con negritas, listas y una tabla): debe quedar limpio y sin tamaños de letra ajenos.
3. En el móvil (Safari iOS y Chrome Android): teclado, selección, enlaces, mover bloques con los botones, tablas anchas (se desplazan) y que las columnas se apilan en la vista previa móvil.
4. Dos pestañas editando: la segunda recibe el aviso de conflicto sin perder lo escrito.
5. Publica y comprueba que no hay `contenteditable` ni `style=` en el HTML, y que `lux-c-…` aparece en `core.css` solo si usaste colores libres.

## Límites conocidos
- Safari/iOS y Android reales no se han podido probar en este entorno: las pruebas automáticas usan Chrome; revisa IME y selección en dispositivos.
- Galerías (Fase 8) y mapas (extensión) pendientes; el bloque de extensión es solo el punto de enganche.
- Sin arrastrar y soltar (se mueve con botones y teclado); sin columnas anidadas; sin tamaños de letra arbitrarios (por diseño, solo preajustes).
- Los avisos de SEO son orientativos; las metaetiquetas avanzadas llegan con la Fase 9.
