# Sitios de prueba

Dos sitios sintéticos, reproducibles con semilla fija. Sin datos reales ni fotos con derechos; las imágenes se generan proceduralmente (degradados, ruido, patrones) con dimensiones y pesos realistas.

## Sitio A — Servicios

| Elemento | Cantidad |
|---|---|
| Páginas estáticas (inicio, servicios, contacto, legal) | 12 |
| Entradas de blog | 60 |
| Categorías / etiquetas | 6 / 20 |
| Formulario de contacto | 1 (endpoint aislado) |
| Imágenes | 80 (hero, logos, ilustraciones) |

Ejercita: menús, blog paginado, archivos, RSS, SEO por página, formulario y redirecciones.

## Sitio B — Portfolio fotográfico

| Elemento | Cantidad |
|---|---|
| Páginas | 8 |
| Galerías | 12, con los 6 diseños previstos (cuadrícula, mosaico, masonry, tira, presentación, editorial) |
| Fotos | 500 en total |
| Originales | de 12 a 36 MP, JPEG/PNG, con EXIF y GPS sintéticos, orientaciones variadas |
| Casos límite | foto minúscula, apaisada extrema, con transparencia, archivo corrupto, falso JPEG |
| Plugin mapa | 1 galería geolocalizada |

Ejercita: biblioteca, recetas WebP, recortes y foco, lightbox, rendimiento de galerías extensas, exportación.

## Volumen conjunto de referencia

≈100 páginas y 500 fotos (protocolo de medición). Los generadores de datos vivirán en `tests/fixtures/` desde la Fase 1.
