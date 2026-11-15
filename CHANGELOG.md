# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/). Versionado semántico a partir de la primera versión instalable.

## [Sin publicar]

### Añadido
- Fase 5: contrato de diseños por carpeta (cabecera, barra superior, hero, pie) con validación estricta e índice cacheado; ajustes con precedencia global/tipo/página/instancia; paletas y colores por rol y componente con auditoría de contraste; tipografía; catálogo local de fuentes WOFF2 con licencia (solo se publican las usadas); logo con proporción protegida; CSS/JS por componente con huella; inventario de recursos por página; bloqueo de dependencias remotas; panel «Diseño» (ver `docs/fase-5.md`, ADR-0012).
- Fase 4: motor de publicación: cola de trabajos con estados, reintentos y recuperación tras caída; preflight con errores y avisos; omisión de publicaciones idénticas; recursos con huella retenidos; guarda de disco; reversión validada (panel y CLI); redirecciones 301 por versión (`.htaccess`) y servicio root validado para Nginx; pruebas de resiliencia con procesos reales (ver `docs/fase-4.md`, ADR-0011).
- Fase 3: contenido y flujo editorial: páginas, entradas y tipos propios con campos por esquema, revisiones, bloqueo optimista, autoguardado, papelera, previsualización privada móvil/escritorio, categorías, etiquetas, menús, slugs con redirecciones, portada y blog; publicación desde la base de datos solo con lo publicado (ver `docs/fase-3.md`, ADR-0010).
- Fase 2: identidad y seguridad del panel: Argon2id, TOTP obligatorio con códigos de recuperación, sesiones en SQLite con rotación y revocación, CSRF, límite de intentos sin enumeración de cuentas, autorización por acción y objeto, auditoría, CLI `admin:*` y `migrate`, CSP y cabeceras del sitio público y del panel (ver `docs/fase-2.md`, ADR-0009).
- Fase 1: CLI `bin/lux` (install, doctor, publish, releases, rollback), publicación atómica con activación por enlace o por renombrado, preflight, entrada mínima del panel, ejemplos de Nginx/PHP-FPM/Apache, pruebas sin dependencias y prueba de humo con Nginx real (ver `docs/fase-1.md`).
- Fase 0: contrato de producto, mapa del repositorio, despliegue y flujos, modelo de amenazas, protocolo de medición, sitios de prueba y ADR iniciales.
