# LuxAeterna

CMS estático de uso general, autohospedable en un VPS económico (Ubuntu + Nginx), con atención especial a portfolios fotográficos y galerías.

El panel administra el contenido; el generador lo publica como HTML, CSS, JS y recursos estáticos. **Nginx sirve la web pública con el panel, la base de datos y el generador apagados.**

> Estado: **Fase 0 — descubrimiento y decisiones** (sin código de aplicación todavía).

## Principios

- Modularidad por carpetas; el núcleo no se edita para añadir diseños o plugins.
- Seguridad por diseño, publicación reversible, recuperación demostrada.
- Sin dependencias pesadas (panel incluido). Web pública pequeña, rápida, accesible e indexable.
- Originales privados; imágenes de contenido servidas en WebP.

## Documentación

| Documento | Contenido |
|---|---|
| [docs/00-contrato-de-producto.md](docs/00-contrato-de-producto.md) | Alcance, requisitos R01–R11 y trazabilidad a fase y verificación |
| [docs/01-mapa-del-repositorio.md](docs/01-mapa-del-repositorio.md) | Estructura de carpetas y responsabilidades |
| [docs/02-despliegue-y-flujos.md](docs/02-despliegue-y-flujos.md) | Diagrama de despliegue, flujos de datos y límites de confianza |
| [docs/03-modelo-de-amenazas.md](docs/03-modelo-de-amenazas.md) | Activos, atacantes, controles y cobertura ASVS |
| [docs/04-protocolo-de-medicion.md](docs/04-protocolo-de-medicion.md) | Metodología, perfiles y presupuestos |
| [docs/05-sitios-de-prueba.md](docs/05-sitios-de-prueba.md) | Web de servicios y portfolio sintéticos |
| [docs/decisiones/](docs/decisiones/) | Decisiones de arquitectura (ADR) |
| [docs/06-decisiones-pendientes.md](docs/06-decisiones-pendientes.md) | Preguntas abiertas para el propietario |
| [CHANGELOG.md](CHANGELOG.md) | Historial de cambios |

Especificación de origen: *Prompt maestro y plan de ejecución del CMS estático v1.0* (proyecto LuxAeterna en Claude).

## Flujo de trabajo

Los cambios se suben directamente a la rama `main`, en commits pequeños y por fase. Ningún secreto, base de datos, original ni copia se versiona (ver `.gitignore`).
