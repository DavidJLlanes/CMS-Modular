# ADR-0001 · Stack técnico: PHP, SQLite, Nginx, JS nativo

Estado: Provisional
Fecha: 2026-11-15

## Contexto
El CMS debe correr en un VPS económico con Ubuntu y Nginx, sin compilación obligatoria para el usuario final, y la web pública debe servirse sin ningún proceso de aplicación (R01, R11, R03).

## Decisión
- **PHP 8.3** (versión incluida en Ubuntu 24.04 LTS, con soporte de seguridad). Se confirma o sube a 8.4 en cuanto D1 aporte datos del VPS real. Requisito mínimo declarado: PHP ≥ 8.3.
- **PHP-FPM** para panel y endpoint de formularios; **PHP CLI** para generador, cola y tareas de mantenimiento.
- **SQLite** (PDO, modo WAL) con un sitio por instancia. Se mide concurrencia en la Fase 14 antes de proponer otra base de datos.
- **Nginx** sirve `releases/current` en solo lectura.
- **JavaScript nativo con módulos ES**, sin paso de compilación obligatorio, tanto en panel como en web pública.
- Autoloader PSR-4 propio y mínimo; Composer solo como herramienta de desarrollo, nunca requerido en producción.

## Alternativas evaluadas
- MySQL/PostgreSQL: más RAM y administración, innecesario para un sitio por instancia.
- Node.js: otro runtime residente; el generador no necesita servidor persistente.
- Bundlers (Vite/Webpack): rompen "sin compilación obligatoria" y añaden dependencias transitivas.

## Consecuencias
- Un solo escritor SQLite a la vez: la publicación y las ediciones usan transacciones cortas y bloqueo por sitio.
- Los plugins PHP corren en proceso: no hay aislamiento (ver modelo de amenazas, #13).

## Verificación / revisión
Se revisa al cerrar la Fase 1 (datos reales del VPS) y la Fase 14 (concurrencia medida).
