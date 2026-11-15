<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\Page;

/** Convierte el modelo estructurado de bloques en HTML semántico. Todo texto se escapa. */
final class BlockRenderer
{
    public static function render(Page $page): string
    {
        $html = '';
        foreach ($page->blocks as $b) {
            $text = htmlspecialchars($b['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= $b['type'] === 'heading'
                ? '<h' . $b['level'] . '>' . $text . '</h' . $b['level'] . ">\n"
                : '<p>' . $text . "</p>\n";
        }
        return $html;
    }
}
