# ADR-0002 · Modularidad por carpetas y contratos versionados

Estado: Aceptada
Fecha: 2026-11-15

## Contexto
R02 exige añadir diseños y plugins sin editar el núcleo, con código mantenible y responsabilidades separadas.

## Decisión
- Un **directorio por unidad** (`components/<tipo>/<id>/`, `plugins/<id>/`) con `manifest.json` validado contra un esquema JSON versionado.
- **Descubrimiento separado de activación**: leer un manifiesto nunca ejecuta código.
- El núcleo expone interfaces pequeñas en `src/Core/`; los módulos las implementan y se registran, sin que el núcleo conozca implementaciones concretas.
- Los formatos de **contenido, configuración, extensiones y exportación** llevan número de versión; cada cambio incompatible exige migración documentada y una ventana de compatibilidad.
- Las plantillas de diseño son de capacidades limitadas con escape por defecto; el código de servidor solo existe en extensiones instaladas por el operador.
- Tamaño de archivo como señal de revisión: 250–300 líneas de referencia, revisión a ~400.

## Alternativas evaluadas
- Plugins por hooks globales dinámicos: difíciles de auditar y de desactivar limpiamente.
- Motor de plantillas de terceros: dependencia y superficie de seguridad innecesarias para la fase inicial.

## Consecuencias
Más disciplina de contratos al inicio (esquemas y ejemplos ejecutables), a cambio de extensibilidad verificable.

## Verificación / revisión
Fase 5 (copiar un diseño y que el panel lo detecte) y Fase 11 (plugin de mapa sin tocar el núcleo).
