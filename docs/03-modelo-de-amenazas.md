# Modelo de amenazas inicial

Versión 0.1 (Fase 0). Se revisa al cierre de cada fase y se verifica en la Fase 14. Referencia: OWASP ASVS (nivel objetivo L2 para el panel; L1 para el sitio estático).

## Activos

| ID | Activo | Impacto si se compromete |
|---|---|---|
| A1 | Credenciales y sesiones de administración | Control total del contenido |
| A2 | Originales fotográficos privados | Pérdida de ingresos/privacidad del autor |
| A3 | SQLite (contenido, revisiones, ajustes) | Pérdida o manipulación editorial |
| A4 | Claves de proveedores IA y secretos | Coste económico, abuso de cuenta |
| A5 | Integridad del sitio publicado | Defacement, malware, SEO spam |
| A6 | Borradores y previsualizaciones | Fuga de contenido no publicado |
| A7 | Datos de formularios (personales) | Fuga de datos personales |
| A8 | GPS/EXIF de originales | Revelar ubicaciones privadas |
| A9 | Disponibilidad del VPS | Caída del sitio |
| A10 | Copias de seguridad | Fuga o pérdida total |

## Atacantes

| ID | Atacante | Capacidad |
|---|---|---|
| T1 | Anónimo de Internet | Peticiones arbitrarias, escaneo, fuerza bruta |
| T2 | Bot de spam | Abuso de formularios |
| T3 | Editor con pocos privilegios | Sesión válida, intenta escalar o ver ajenos |
| T4 | Contenido hostil (archivo subido, texto pegado, respuesta de IA, endpoint remoto) | Datos no confiables dentro del sistema |
| T5 | Plugin/dependencia comprometida | Ejecución en el proceso del CMS |
| T6 | Atacante con acceso a copias o logs | Lectura de datos en reposo |

## Rutas de entrada, consecuencias y controles

| # | Amenaza | Activos | Controles previstos | Fase | Verificación |
|---|---|---|---|---|---|
| 1 | Fuerza bruta / enumeración de cuentas | A1 | Límite de intentos, respuestas uniformes, Argon2id, MFA en producción | 2 | Pruebas de límites y mensajes |
| 2 | Robo/fijación de sesión | A1 | Cookies Secure/HttpOnly/SameSite, rotación al login y cambios de privilegio, expiración | 2 | Pruebas de sesión caducada y manipulada |
| 3 | CSRF | A3, A5 | Token por mutación, SameSite | 2 | Peticiones forjadas rechazadas |
| 4 | IDOR / escalada | A3, A6 | Autorización de servidor por acción y objeto | 2, 3 | Pruebas por rol y objeto |
| 5 | Inyección SQL | A3 | PDO con consultas preparadas, validación de tipos | 2 | Pruebas dirigidas, revisión estática |
| 6 | XSS almacenado/reflejado en panel y web | A1, A5 | Escape por contexto, sanitización por reglas explícitas, CSP probada por separado | 2, 7 | Corpus de payloads; CSP sin `unsafe-inline` donde sea viable |
| 7 | HTML activo en el editor | A5 | Rechazo en navegador **y** servidor; generación desde modelo estructurado | 7 | Pegado contaminado desde Word/web |
| 8 | Subida maliciosa (polimórficos, bombas de píxeles/descompresión) | A2, A9 | Validación por contenido, límites de píxeles/CPU/RAM/tiempo, re-codificación, originales fuera de la web | 6 | Archivos inválidos y bombas de prueba |
| 9 | Salto de directorio / enlaces simbólicos | A2, A3 | Normalización de rutas, rechazo de `..`, symlinks y rutas absolutas en diseños, plugins e importación | 5, 11, 13 | Pruebas de manifiestos y zips hostiles |
| 10 | Exposición de `var/`, SQLite, copias | A2–A4, A10 | Fuera de la raíz web; Nginx sin alias; prueba externa de acceso | 1 | Peticiones externas a rutas conocidas devuelven 404 |
| 11 | Publicación accidental de borradores | A6 | Borradores fuera de la instantánea pública; preflight; noindex/no-store en previews | 3, 4, 9 | Matriz de URLs y búsqueda en el árbol publicado |
| 12 | Versión de sitio corrupta o manipulada | A5 | Generación en temporal, preflight, cambio atómico, reversión, integridad de artefactos | 4 | Simulacros de fallo |
| 13 | Plugin/tema malicioso | A1–A5 | Manifiesto nunca ejecuta código; solo instala el operador; revisión de dependencias; el panel no escribe código ejecutable | 5, 11 | Revisión de contratos |
| 14 | SSRF vía endpoints de IA | A4, A9 | Registro de proveedores permitido; validación de protocolo/host; bloqueo de IP privadas, loopback y metadatos; control de redirecciones | 12 | Pruebas SSRF |
| 15 | Fuga de claves de IA | A4 | Cifrado con clave fuera de BD y raíz pública; enmascarado; exclusión de logs, exportación, historial, frontend | 12 | Búsqueda de claves en logs/exportaciones |
| 16 | Prompt injection / respuesta IA hostil | A5 | Sugerencias como datos; aceptación humana; no ejecuta código, ni cambia permisos, ni publica | 12 | Respuestas maliciosas simuladas |
| 17 | Abuso de formularios | A7, A9 | Validación servidor, límites de tamaño/tasa, endpoint aislado, retención definida | 13 | Pruebas de abuso |
| 18 | Importador (zip bomb, rutas externas, sobrescritura) | A3, A9 | Área temporal, validación previa, límites de expansión | 13 | Archivos manipulados |
| 19 | Fuga de GPS/EXIF | A8 | Eliminación de metadatos privados; solo se publica la ubicación elegida | 6, 11 | Inspección de derivados |
| 20 | Pérdida de datos / ransomware | A3, A10 | SQLite backup consistente, copia fuera del VPS, restauración probada, RPO/RTO medidos | 13, 14 | Restauración real en entorno limpio |
| 21 | Cabeceras y clickjacking | A1, A5 | CSP, anti-framing, nosniff, Referrer-Policy; HSTS tras validar HTTPS | 2 | Comprobación de cabeceras |
| 22 | Reverse proxy mal configurado (IP de cliente falsificada) | A1 | Lista explícita de proxies de confianza para límites de tasa | 2 | Prueba con cabeceras falsificadas |

## Cobertura ASVS (planificada)

| Capítulo ASVS | Aplicabilidad | Fase de verificación |
|---|---|---|
| Autenticación / sesiones | Panel | 2, 14 |
| Control de acceso | Panel | 2, 3, 14 |
| Validación, saneamiento y codificación | Panel, editor, importador | 2, 7, 13 |
| Gestión de archivos | Biblioteca multimedia | 6 |
| Criptografía y secretos | Claves IA, contraseñas | 2, 12 |
| Registro y errores | Transversal | 1, 2 |
| Protección de datos | Formularios, EXIF | 6, 13 |
| Comunicaciones (TLS) | Nginx | 1, 14 |
| Configuración | VPS, cabeceras, dependencias | 1, 14 |
| Lógica de negocio / API | Publicación, plugins | 4, 11 |

El informe de la Fase 14 declarará qué requisitos ASVS concretos se verificaron, cuáles no aplican y cuáles quedan pendientes; hasta entonces no se afirma cumplimiento.

## Supuestos y límites

- Un único operador de confianza instala plugins y diseños con código; un editor no.
- Una imagen publicada puede descargarse o capturarse; la protección de galerías es solo disuasoria.
- El aislamiento por usuario de sistema depende de una configuración correcta del VPS (Fase 1 y auditoría de Fase 14).
