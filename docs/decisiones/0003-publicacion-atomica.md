# ADR-0003 · Publicación atómica con `releases/` y `current`

Estado: Aceptada
Fecha: 2026-11-15

## Contexto
La web activa nunca debe quedar a medias ni depender de procesos del CMS (R01, R10). Debe poder revertirse rápido.

## Decisión
- Cada publicación genera una carpeta nueva `releases/<id>/` en una **zona temporal del mismo sistema de archivos** que el destino.
- Un **preflight** (enlaces, colisiones, recursos, HTML, indexación, exclusión de privados) decide si se activa. Errores bloquean; advertencias se muestran.
- La activación es el **cambio atómico del enlace simbólico `current`** (crear enlace temporal y `rename`).
- `releases/` y `current` están en un directorio operativo **separado del código**.
- Recursos con huella de contenido y caché larga; el HTML se revalida. Se conservan los recursos de versiones recientes para clientes con HTML antiguo; la retención se limita por espacio.
- Bloqueo por sitio: una sola publicación a la vez.
- Las reglas HTTP que cambian con la publicación (redirecciones) se generan por versión, se validan con `nginx -t` y, si fallan, se conserva la versión anterior.

## Alternativas evaluadas
- Generar sobre el directorio público: ventanas de inconsistencia y sin reversión.
- Despliegue por rsync directo: no atómico.

## Consecuencias
Requiere espacio para al menos dos versiones y política de limpieza segura. La reversión es reapuntar `current`.

## Verificación / revisión
Simulacros de la Fase 4: publicación doble, caída del proceso, disco casi lleno, error de configuración, dependencia inexistente.
