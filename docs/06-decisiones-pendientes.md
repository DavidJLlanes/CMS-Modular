# Decisiones pendientes del propietario

Mientras no se resuelvan, se avanza con el valor provisional indicado y en tareas independientes.

| # | Pregunta | Valor provisional | Afecta a | Necesaria antes de |
|---|---|---|---|---|
| D1 | Recursos reales del VPS. **Proveedor: IONOS.** Faltan vCPU, RAM, disco, PHP disponible y acceso root/SSH | 1 vCPU, 1 GB, Ubuntu LTS, PHP 8.3 | Mediciones, ADR-0001/0004/0006 | Fase 1 (cierre) |
| D2 | Método de despliegue (git pull, rsync, paquete) | `git pull` de una etiqueta + script en `deploy/` | Fase 1 | Fase 1 |
| D3 | ¿Soportar sitios que viven en una subcarpeta (`dominio.com/web/`) además de la raíz (`dominio.com/`)? | Solo raíz, con URL base explícita | Rutas, PWA, canonicals | Fase 1 |
| D4 | ✅ **Resuelta (2026-11-15): ruta `/admin`** | Ruta `/admin` (prefijo configurable) | Nginx, cookies, CSP | Fase 1 |
| D5 | ¿Experiencia sin conexión (service worker) en la PWA? | No; solo instalable | Fase 10 | Fase 10 |
| D6 | Idiomas del sitio y del panel | Español; arquitectura preparada para más | Fuentes, SEO, contenido | Fase 3 |
| D7 | Proveedor de mapas (recursos estáticos o tercero) | Lista de puntos estática + mapa bajo demanda | Fase 11 | Fase 11 |
| D8 | Destino de copias fuera del VPS | Por definir | Fase 13 | Fase 13 |
| D9 | MFA obligatorio en producción (TOTP) | Sí, TOTP | Fase 2 | Fase 2 |
| D10 | Licencia del proyecto | Sin licencia (todos los derechos reservados) | Repositorio | Primera versión |
