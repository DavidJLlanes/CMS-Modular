<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\BlockValidator;
use LuxAeterna\Content\Inline;
use LuxAeterna\Content\Page;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Media\ImageRenderer;

/** Convierte el modelo estructurado de bloques en HTML semántico. Todo texto se escapa; no hay HTML libre. */
final class BlockRenderer
{
    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function render(Page $page, ?ImageRenderer $images = null, ?\LuxAeterna\Gallery\GalleryRenderer $galleries = null): string
    {
        $html = '';
        if ($page->type !== 'page' && $page->type !== 'listing' && $page->date !== null) {
            $html .= '<p class="meta"><time datetime="' . gmdate('Y-m-d', $page->date) . '">' . gmdate('d/m/Y', $page->date) . '</time></p>' . "\n";
        }
        $ctx = new \stdClass();
        $ctx->images = $images;
        $ctx->galleries = $galleries;
        $ctx->seenImage = false;
        foreach ($page->blocks as $i => $b) {
            $ctx->top = $i;
            $html .= self::block($b, $ctx);
        }
        if ($page->fields !== []) {
            $html .= '<dl class="fields">';
            foreach ($page->fields as $f) {
                $html .= '<dt>' . self::e($f['label']) . '</dt><dd>' . self::e($f['value']) . '</dd>';
            }
            $html .= "</dl>\n";
        }
        if ($page->terms !== []) {
            $html .= '<p class="terms">' . self::e(implode(' · ', $page->terms)) . "</p>\n";
        }
        return $html;
    }

    /** @param array<string,mixed> $b @param \stdClass $ctx imágenes, posición del bloque de primer nivel y si ya se vio una imagen */
    private static function block(array $b, \stdClass $ctx): string
    {
        switch ($b['type']) {
            case 'heading':
                $l = (int)$b['level'];
                return '<h' . $l . self::cls($b) . '>' . self::rich($b) . '</h' . $l . ">\n";
            case 'paragraph':
                return '<p' . self::cls($b) . '>' . self::rich($b) . "</p>\n";
            case 'quote':
                $c = trim((string)($b['cite'] ?? ''));
                return '<blockquote' . self::cls($b) . '><p>' . self::rich($b) . '</p>' . ($c !== '' ? '<footer>' . self::e($c) . '</footer>' : '') . "</blockquote>\n";
            case 'list':
                $tag = !empty($b['ordered']) ? 'ol' : 'ul';
                return "<$tag>\n" . implode('', array_map(static fn(mixed $i): string => '<li>' . (is_string($i) ? self::e($i) : Inline::html((array)$i['inlines'])) . "</li>\n", $b['items'])) . "</$tag>\n";
            case 'mount': // solo la pantalla de edición visual: punto de montaje del editor dentro de la plantilla real
                return "<div id=\"lux-editor-mount\"></div>\n";
            case 'links': // listados generados por el sistema (rutas internas ya validadas)
                return "<ul class=\"listing\">\n" . implode('', array_map(static fn(array $i): string => '<li><a href="' . self::e($i['url']) . '">' . self::e($i['text']) . '</a>'
                    . ($i['note'] !== '' ? ' <small>' . self::e($i['note']) . '</small>' : '') . "</li>\n", $b['items'])) . "</ul>\n";
            case 'image':
                if ($ctx->images === null) {
                    throw new LuxException('Hay una imagen que publicar pero no se ha preparado la biblioteca multimedia.');
                }
                // La primera imagen entre los dos primeros bloques de primer nivel es candidata a LCP: sin carga diferida y con prioridad.
                $fig = $ctx->images->figure($b, !$ctx->seenImage && $ctx->top < 2);
                $ctx->seenImage = true;
                return isset($b['align']) ? preg_replace('/^<figure class="lux-img"/', '<figure class="lux-img lux-img--' . $b['align'] . '"', $fig, 1) : $fig;
            case 'gallery':
                if ($ctx->galleries === null) {
                    throw new LuxException('Hay una galería que publicar pero no se ha preparado el módulo de galerías.');
                }
                return $ctx->galleries->render((string)$b['ref']);
            case 'button':
                return '<p class="lux-btn-row' . (isset($b['align']) ? ' lux-a-' . $b['align'] : '') . '"><a class="lux-btn lux-btn--' . $b['variant'] . '" href="' . self::e((string)$b['href']) . '"'
                    . (preg_match('#^https?://#i', (string)$b['href']) ? ' rel="noopener noreferrer"' : '') . '>' . self::e((string)$b['label']) . "</a></p>\n";
            case 'separator':
                return $b['style'] === 'space' ? "<div class=\"lux-space\" aria-hidden=\"true\"></div>\n" : '<hr class="lux-sep lux-sep--' . $b['style'] . "\">\n";
            case 'columns':
                $h = '<div class="lux-cols lux-cols--' . $b['layout'] . ' lux-cols--gap-' . $b['gap'] . ($b['valign'] === 'center' ? ' lux-cols--center' : '') . "\">\n";
                foreach ($b['cols'] as $col) { // el orden en el HTML es el orden de lectura; el móvil apila en ese mismo orden
                    $h .= '<div class="lux-col">' . implode('', array_map(static fn(array $x): string => self::block($x, $ctx), $col)) . "</div>\n";
                }
                return $h . "</div>\n";
            case 'table':
                return self::table($b);
            case 'extension':
                $ext = BlockValidator::extension((string)$b['kind']);
                if ($ext === null) {
                    throw new LuxException('Una extensión de bloques usada en el contenido ya no está instalada (' . (string)$b['kind'] . ').');
                }
                return (string)($ext['render'])($b['data']);
            default:
                throw new LuxException('Bloque no publicable: ' . (string)$b['type']);
        }
    }

    /** @param array<string,mixed> $b */
    private static function rich(array $b): string
    {
        return isset($b['inlines']) ? Inline::html($b['inlines']) : nl2br(self::e((string)($b['text'] ?? '')), false);
    }

    /** Clases de alineación y tipografía (valores admitidos → clases; nunca estilos en línea). @param array<string,mixed> $b */
    private static function cls(array $b): string
    {
        $c = [];
        if (isset($b['align'])) {
            $c[] = 'lux-a-' . $b['align'];
        }
        foreach ((array)($b['style'] ?? []) as $k => $v) {
            $c[] = match ($k) {
                'italic' => 'lux-t-italic',
                'color' => Inline::colorClass((string)$v),
                default => 'lux-t-' . $k . '-' . $v,
            };
        }
        return $c === [] ? '' : ' class="' . implode(' ', $c) . '"';
    }

    /** Tabla semántica con encabezados `th scope`, título (`caption`) y región desplazable accesible con el teclado para pantallas pequeñas. @param array<string,mixed> $b */
    private static function table(array $b): string
    {
        $cap = trim((string)$b['caption']);
        $cell = static fn(mixed $c): string => is_string($c) ? nl2br(self::e($c), false) : Inline::html((array)$c['inlines']);
        $h = '<div class="lux-table-wrap" role="region" tabindex="0"' . ($cap !== '' ? ' aria-label="' . self::e($cap) . '"' : ' aria-label="Tabla"') . '><table class="lux-table">';
        $h .= $cap !== '' ? '<caption>' . self::e($cap) . '</caption>' : '';
        foreach ($b['rows'] as $r => $row) {
            $h .= '<tr>';
            foreach ($row as $c => $v) {
                $al = $b['aligns'][$c] !== 'left' ? ' class="lux-a-' . $b['aligns'][$c] . '"' : '';
                if ($b['header_row'] && $r === 0) {
                    $h .= '<th scope="col"' . $al . '>' . $cell($v) . '</th>';
                } elseif ($b['header_col'] && $c === 0) {
                    $h .= '<th scope="row"' . $al . '>' . $cell($v) . '</th>';
                } else {
                    $h .= '<td' . $al . '>' . $cell($v) . '</td>';
                }
            }
            $h .= '</tr>';
        }
        return $h . "</table></div>\n";
    }
}
