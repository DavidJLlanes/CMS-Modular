# Protocolo de medición

Versión 0.1. Define cómo se mide para que los resultados sean comparables entre fases. Las cifras de presupuesto son **propuestas iniciales**; solo se cambian con mediciones y una decisión registrada.

## Entornos

| Entorno | Descripción | Etiqueta en informes |
|---|---|---|
| Referencia | 1 vCPU, 1 GB RAM total, Ubuntu LTS, Nginx, PHP 8.3 | `sintético-ref` |
| VPS real | El servidor objetivo del propietario, cuando haya acceso | `vps-real` |
| Desarrollo | Máquina de desarrollo; no vale para cifras de rendimiento | `dev` |

Cada informe declara entorno, versión de PHP/Nginx/GD/Imagick, commit y fecha. Sin credenciales ni datos privados.

## Perfiles de cliente

| Perfil | Dispositivo | Red | CPU |
|---|---|---|---|
| Móvil | 412×823, DPR 2,6 | 4G lenta (≈1,6 Mbps, 150 ms RTT) | ×4 ralentizada |
| Escritorio | 1350×940, DPR 1 | Cable (≈10 Mbps, 40 ms RTT) | sin límite |

Cada medición se hace con **caché fría** y **caché caliente**. Mínimo 5 ejecuciones; se reporta la mediana y el rango.

## Métricas

**Publicación:** tiempo de publicación fría y caliente; tiempo por foto; CPU; pico de RSS; disco usado; páginas generadas.
**Web pública:** número de peticiones; bytes transferidos comprimidos (gzip/brotli); TTFB estático; LCP, CLS, INP (laboratorio; campo cuando haya volumen).
**Panel:** peso inicial JS/CSS comprimido; memoria PHP-FPM por petición; tiempo de guardado.
**Servidor:** RAM total usada con el sistema incluido; carga con panel y generador detenidos.

## Volumen de medición

100 páginas y 500 fotos repartidas en galerías (ver [05-sitios-de-prueba.md](05-sitios-de-prueba.md)). Se declara qué condiciones son sintéticas y cuáles proceden del VPS real.

## Presupuestos iniciales propuestos

| Concepto | Presupuesto | Notas |
|---|---|---|
| HTML + CSS iniciales, página de texto sin imágenes ni fuentes | ≤ 50 KiB comprimidos | |
| JS público en página simple sin interacción | 0 KiB | |
| Núcleo común de galería + visor | ≤ 30 KiB comprimidos | Sin efectos avanzados |
| Tipografías, fotos, efectos | Medidos por separado | |
| Panel (JS+CSS inicial) | A fijar en Fase 2 con medición | R03 |
| Generador | A fijar en Fase 4 (RAM pico, tiempo) | |

## Procedimiento de reproducción

1. Preparar entorno y registrar versiones.
2. Generar los sitios de prueba con la semilla documentada.
3. Ejecutar publicación fría; repetir en caliente.
4. Medir web con Lighthouse/DevTools en los dos perfiles y en ambos estados de caché; capturar HAR.
5. Guardar resultados en `docs/mediciones/AAAA-MM-DD-<entorno>.md` (se creará en la Fase 1 junto al primer informe).

## Inventario de dependencias (R03)

Cada dependencia se registra en `docs/dependencias.md` con: necesidad, peso instalado, tamaño transferido si llega al navegador, transitivas, mantenimiento, licencia, superficie de seguridad y alternativa evaluada (ADR-0005).
