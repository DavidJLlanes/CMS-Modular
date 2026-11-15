# ADR-0007 · Compatibilidad con hosting compartido barato

Estado: Aceptada
Fecha: 2026-11-15

## Contexto
El propietario aclara que el CMS debe estar optimizado para correr en hostings compartidos baratos, además de en su VPS IONOS (4 vCore, 4 GB). Un hosting compartido suele ofrecer: Apache con `.htaccess` (a veces LiteSpeed o Nginx gestionado), PHP 8.1–8.3 con `memory_limit` de 128–256 MB y `max_execution_time` de 30 s, sin acceso root, sin procesos residentes, sin garantía de enlaces simbólicos, cron cada 1–15 min y a veces sin SSH. Todo el PHP corre bajo un único usuario.

## Decisión
Se definen **dos perfiles de despliegue soportados**, ambos de primera clase:

| Perfil | Servidor | Acceso | Uso |
|---|---|---|---|
| **A · VPS** | Nginx + PHP-FPM, usuarios de sistema separados | root/SSH | IONOS del propietario |
| **B · Hosting compartido** | Apache/LiteSpeed + `.htaccess`, PHP del proveedor | FTP/panel, SSH opcional | Hosting barato |

El diseño parte del **mínimo común (perfil B)** y el perfil A solo añade endurecimiento:

1. **PHP ≥ 8.1** con extensiones habituales (`pdo_sqlite`, `mbstring`, `json`, `gd` con WebP). Se prueba en 8.1 y 8.3.
2. **Sin procesos residentes.** La cola se procesa por **lotes cortos** dentro de un presupuesto de tiempo y memoria, disparados por cron (CLI) o, si no hay cron, por peticiones autenticadas del panel que avanzan un lote y devuelven progreso. Todo trabajo es reanudable.
3. **Presupuesto de recursos por operación:** pico ≤ 96 MB de RAM y ≤ 20 s por lote por defecto, configurables; se lee `memory_limit` y `max_execution_time` y se adapta el tamaño del lote.
4. **Reglas HTTP en ambos formatos:** el generador emite `.htaccess` (redirecciones, 404/410, cabeceras, caché, MIME WebP) y fragmentos Nginx. La salida estática también funciona sin reglas especiales (degrada a redirecciones por HTML con `noindex` solo como último recurso documentado).
5. **Datos privados.** Si el hosting permite carpetas fuera de la raíz web, `var/` va fuera. Si no, va dentro con `.htaccess` de denegación total más **comprobación automática en el diagnóstico** de que no es accesible por HTTP; la instalación se niega a continuar si lo es.
6. **Publicación atómica con respaldo:** `current` como enlace simbólico si el diagnóstico confirma que funciona; si no, intercambio por **renombrado de directorios** (ver ADR-0003, enmienda).
7. **Instalador doble:** CLI (`bin/lux install`) y **instalador web de un solo uso** que se autobloquea y se elimina o desactiva al terminar, protegido por token de instalación.
8. **Sin Composer ni compilación en el servidor**; el paquete de release es un único `.zip` subible por FTP.
9. **Mediciones con perfil B:** además del entorno VPS, se mide con límites de 1 vCPU compartida, `memory_limit=128M`, `max_execution_time=30`.

## Alternativas evaluadas
- Solo VPS: excluye al público objetivo de bajo coste.
- Exigir PHP-FPM propio o procesos residentes: imposible en hosting compartido.

## Consecuencias
- El aislamiento por usuarios de sistema (perfil A) no existe en B: la seguridad descansa en rutas privadas, `.htaccess`, permisos de archivo y validación (ver modelo de amenazas).
- Más trabajo en generación de reglas duales y en lotes reanudables.
- Las funciones que exijan algo fuera del mínimo (procesar lotes enormes de fotos) se documentan con alternativa: procesar en un PC y subir los derivados.

## Verificación / revisión
Matriz de pruebas por perfil en cada fase; diagnóstico `lux doctor` con veredicto A/B; Fase 14 repite la batería en un hosting compartido real.
