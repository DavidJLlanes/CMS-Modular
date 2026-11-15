<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

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

    public static function render(Page $page, ?ImageRenderer $images = null): string
    {
        $html = '';
        if ($page->type !== 'page' && $page->type !== 'listing' && $page->date !== null) {
            $html .= '<p class="meta"><time datetime="' . gmdate('Y-m-d', $page->date) . '">' . gmdate('d/m/Y', $page->date) . '</time></p>' . "\n";
        }
        $seenImage = false;
        foreach ($page->blocks as $i => $b) {
            if ($b['type'] === 'image' && $images !== null) {
                // La primera imagen entre los dos primeros bloques es candidata a LCP: sin carga diferida y con prioridad.
                $html .= $images->figure($b, !$seenImage && $i < 2);
                $seenImage = true;
                continue;
            }
            $html .= self::block($b);
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

    /** @param array<string,mixed> $b */
    private static function block(array $b): string
    {
        switch ($b['type']) {
            case 'heading':
                return '<h' . (int)$b['level'] . '>' . self::e((string)$b['text']) . '</h' . (int)$b['level'] . ">\n";
            case 'paragraph':
                return '<p>' . self::e((string)$b['text']) . "</p>\n";
            case 'quote':
                return '<blockquote><p>' . self::e((string)$b['text']) . "</p></blockquote>\n";
            case 'list':
                return "<ul>\n" . implode('', array_map(static fn(string $i): string => '<li>' . self::e($i) . "</li>\n", $b['items'])) . "</ul>\n";
            case 'links': // listados generados por el sistema (rutas internas ya validadas)
                return "<ul class=\"listing\">\n" . implode('', array_map(static fn(array $i): string => '<li><a href="' . self::e($i['url']) . '">' . self::e($i['text']) . '</a>'
                    . ($i['note'] !== '' ? ' <small>' . self::e($i['note']) . '</small>' : '') . "</li>\n", $b['items'])) . "</ul>\n";
            case 'image':
                throw new LuxException('Hay una imagen que publicar pero no se ha preparado la biblioteca multimedia.');
            case 'gallery':
                throw new LuxException('Las galerías se publican a partir de la Fase 8. Quita el bloque de galería (' . (string)$b['ref'] . ') o espera a esa fase.');
            default:
                throw new LuxException('Bloque no publicable: ' . (string)$b['type']);
        }
    }
}
