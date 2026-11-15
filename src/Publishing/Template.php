<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Core\LuxException;

/**
 * Plantillas de capacidades limitadas (ADR-0002): sustitución de marcadores, sin lógica ni código.
 * {{ nombre }} escapa HTML (por defecto). {{{ nombre }}} inserta HTML ya preparado por el generador.
 */
final class Template
{
    /** @param array<string,string> $vars */
    public static function render(string $template, array $vars): string
    {
        $out = preg_replace_callback('/\{\{\{\s*([a-z_]+)\s*\}\}\}|\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($vars): string {
            $raw = ($m[1] ?? '') !== '';
            $name = $raw ? $m[1] : $m[2];
            if (!array_key_exists($name, $vars)) {
                throw new LuxException("La plantilla usa un marcador desconocido: '$name'.");
            }
            return $raw ? $vars[$name] : htmlspecialchars($vars[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $template);
        return (string)$out;
    }
}
