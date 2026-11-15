# ADR-0005 · Política de dependencias (R03)

Estado: Aceptada
Fecha: 2026-11-15

## Contexto
R03 prohíbe dependencias pesadas en todo el CMS, incluido el panel.

## Decisión
1. Toda dependencia cubre una **necesidad explícita** y se registra en `docs/dependencias.md` antes de incorporarse: peso instalado, tamaño transferido (si llega al navegador), dependencias transitivas, mantenimiento, licencia, superficie de seguridad y alternativa evaluada.
2. Se prefieren **funciones nativas** de PHP y del navegador.
3. Para operaciones delicadas (sanitización HTML, criptografía, hash de contraseñas, TOTP) se usan **primitivas del lenguaje o bibliotecas pequeñas y mantenidas**; no se reimplementan primitivas de seguridad.
4. Sin frameworks de UI, sin bundlers obligatorios, sin CDN ni recursos remotos en el sitio público.
5. Cada versión revisa dependencias y licencias antes de publicarse.
6. Las dependencias de plugins se versionan y validan (Fase 11) y cuentan en el presupuesto de la página donde se carguen.

## Alternativas evaluadas
- Framework PHP completo: velocidad inicial, pero peso, transitivas y superficie contrarias a R03.
- Editor de terceros (ProseMirror, TipTap): solo si resuelve una necesidad verificada sin romper R03 (Fase 7).

## Consecuencias
Más código propio en zonas no críticas; menos superficie de ataque y menos peso.

## Verificación / revisión
Inventario en cada cierre de fase y revisión completa en la Fase 14.
