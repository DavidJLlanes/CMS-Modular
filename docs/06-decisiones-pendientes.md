# Decisiones pendientes del propietario

Mientras no se resuelvan, se avanza con el valor provisional indicado y en tareas independientes.

| # | Pregunta | Valor provisional | Afecta a | Necesaria antes de |
|---|---|---|---|---|
| D1 | ✅ **Parcial (2026-11-15): IONOS, 4 vCore, 4 GB RAM.** Faltan disco y PHP disponible | `vps-real` 4 vCore / 4 GB; diseño contra `sintético-b` | Mediciones, ADR-0001/0004/0006 | Fase 1 (cierre) |
| D2 | Método de despliegue (git pull, rsync, paquete) | `git pull` de una etiqueta + script en `deploy/` | Fase 1 | Fase 1 |
| D3 | ✅ **Resuelta (2026-11-15): solo raíz.** Dominio `https://luxaeterna.es`; URL base explícita, subcarpeta fuera de alcance | Solo raíz | Rutas, PWA, canonicals | Fase 1 |
| D4 | ✅ **Resuelta (2026-11-15): ruta `/admin`** | Ruta `/admin` (prefijo configurable) | Nginx, cookies, CSP | Fase 1 |
| D5 | ¿Experiencia sin conexión (service worker) en la PWA? | No; solo instalable | Fase 10 | Fase 10 |
| D6 | Idiomas del sitio y del panel | Español; arquitectura preparada para más | Fuentes, SEO, contenido | Fase 3 |
| D7 | Proveedor de mapas (recursos estáticos o tercero) | Lista de puntos estática + mapa bajo demanda | Fase 11 | Fase 11 |
| D8 | Destino de copias fuera del VPS | Por definir | Fase 13 | Fase 13 |
| D9 | MFA obligatorio en producción (TOTP) | Sí, TOTP | Fase 2 | Fase 2 |
| D10 | Licencia del proyecto | Sin licencia (todos los derechos reservados) | Repositorio | Primera versión |
| D11 | ✅ **Resuelta (2026-11-15): servidor IONOS de Nginx y correo, PHP 8.3.6, ~100 GB libres, instalado en `/var/www/luxaeterna.es`** | — | — | — |
| D12 | ¿Hosting compartido concreto para pruebas reales (proveedor, plan, PHP, SSH/cron)? | Simulado con `sintético-b` | Fase 14 | Fase 14 |
