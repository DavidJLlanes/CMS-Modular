# ADR-0006 · Entorno de referencia para mediciones

Estado: Provisional
Fecha: 2026-11-15

## Contexto
Aún no hay datos del VPS objetivo (decisión pendiente D1), pero el diseño y los presupuestos necesitan una base común.

## Decisión
Mientras no haya datos reales se mide con **1 vCPU, 1 GB de RAM total** (incluyendo memoria del sistema), Ubuntu 24.04 LTS, Nginx y PHP 8.3. Todos los informes etiquetan el entorno (`sintético-ref` o `vps-real`) y no se mezclan cifras de entornos distintos.

## Consecuencias
Si el VPS real es menor, se revisan presupuestos y ADR-0001/0004. Si es mayor, la referencia se mantiene como suelo conservador.

## Verificación / revisión
Se sustituye al recibir el inventario real (D1) y se revisa al cierre de la Fase 1.
