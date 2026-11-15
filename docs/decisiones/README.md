# Decisiones de arquitectura (ADR)

Se registra un ADR cuando una elección afecta al contrato del contenido, los módulos, la seguridad, la compatibilidad o el despliegue.

| ADR | Título | Estado |
|---|---|---|
| [0001](0001-stack-tecnico.md) | Stack técnico: PHP, SQLite, Nginx, JS nativo | Provisional |
| [0002](0002-modularidad-y-contratos.md) | Modularidad por carpetas y contratos versionados | Aceptada |
| [0003](0003-publicacion-atomica.md) | Publicación atómica con `releases/` y `current` | Aceptada |
| [0004](0004-procesado-de-imagenes.md) | Procesado de imágenes y WebP | Provisional |
| [0005](0005-politica-de-dependencias.md) | Política de dependencias (R03) | Aceptada |
| [0006](0006-entorno-de-referencia.md) | Entorno de referencia para mediciones | Provisional |
| [0007](0007-compatibilidad-hosting-compartido.md) | Compatibilidad con hosting compartido barato | Aceptada |
| [0008](0008-configuracion-y-rutas.md) | Formatos de configuración y convenciones de rutas | Aceptada |
| [0009](0009-identidad-sesiones-y-mfa.md) | Identidad, sesiones y segundo factor | Aceptada |
| [0010](0010-contenido-revisiones-y-previsualizacion.md) | Contenido, revisiones, URLs y previsualización | Aceptada |
| [0011](0011-motor-de-publicacion-y-reversion.md) | Motor de publicación, coherencia y reversión | Aceptada |

## Plantilla

```
# ADR-NNNN · Título
Estado: Propuesta | Provisional | Aceptada | Sustituida por ADR-XXXX
Fecha: AAAA-MM-DD

## Contexto
## Decisión
## Alternativas evaluadas
## Consecuencias
## Verificación / revisión
```
