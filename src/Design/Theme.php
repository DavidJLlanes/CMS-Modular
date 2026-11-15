<?php
declare(strict_types=1);

namespace LuxAeterna\Design;

/**
 * Tema del sitio: colores por rol y por componente, y tipografía. Todo se traduce a variables CSS (--lux-*) y a un
 * número reducido de clases reutilizables; los diseños consumen variables, nunca valores propios.
 */
final class Theme
{
    public const ROLES = ['bg' => 'Fondo', 'surface' => 'Superficie', 'text' => 'Texto', 'muted' => 'Texto secundario', 'primary' => 'Color principal', 'primary_text' => 'Texto sobre el principal', 'link' => 'Enlaces', 'border' => 'Bordes'];
    public const COMPONENT_ROLES = ['header' => ['bg', 'text', 'link'], 'topbar' => ['bg', 'text'], 'hero' => ['bg', 'text'], 'footer' => ['bg', 'text', 'link']];
    public const PALETTES = [
        'claro' => ['bg' => '#ffffff', 'surface' => '#f6f4ef', 'text' => '#1d1d1f', 'muted' => '#555555', 'primary' => '#7a5c00', 'primary_text' => '#ffffff', 'link' => '#7a5c00', 'border' => '#d9d4c7'],
        'oscuro' => ['bg' => '#121212', 'surface' => '#1e1e1e', 'text' => '#f2f2f2', 'muted' => '#b5b5b5', 'primary' => '#f0c45a', 'primary_text' => '#1a1400', 'link' => '#f0c45a', 'border' => '#3a3a3a'],
        'oceano' => ['bg' => '#ffffff', 'surface' => '#eef4f8', 'text' => '#10242f', 'muted' => '#43545f', 'primary' => '#0b5a80', 'primary_text' => '#ffffff', 'link' => '#0b5a80', 'border' => '#c9d8e2'],
        'contraste' => ['bg' => '#ffffff', 'surface' => '#ffffff', 'text' => '#000000', 'muted' => '#262626', 'primary' => '#000000', 'primary_text' => '#ffffff', 'link' => '#0000b3', 'border' => '#000000'],
    ];
    public const ALIGN = ['left' => 'Izquierda', 'center' => 'Centrado', 'justify' => 'Justificado'];
    public const SPACING = ['compact' => '.6', 'normal' => '1', 'airy' => '1.5'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'colors' => self::PALETTES['claro'],
            'components' => [],
            'typography' => [
                'body' => ['family' => 'system', 'size' => 17, 'line' => 1.6, 'align' => 'left', 'weights' => [400, 700]],
                'heading' => ['family' => 'system', 'weight' => 700],
                'spacing' => 'normal',
                'width' => 46,
            ],
        ];
    }

    /** Valida y normaliza el tema recibido; completa con los valores por defecto. @param array<string,mixed> $in @throws \InvalidArgumentException */
    public static function normalize(array $in, ?FontCatalog $fonts = null): array
    {
        $d = self::defaults();
        $hex = static function (mixed $v, string $what): string {
            if (!is_string($v) || !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                throw new \InvalidArgumentException("Color no válido en «{$what}» (usa #rrggbb).");
            }
            return strtolower($v);
        };
        $colors = $d['colors'];
        foreach ((array)($in['colors'] ?? []) as $r => $v) {
            if (!isset(self::ROLES[$r])) {
                throw new \InvalidArgumentException("Rol de color desconocido: $r.");
            }
            $colors[$r] = $hex($v, self::ROLES[$r]);
        }
        $comp = [];
        foreach ((array)($in['components'] ?? []) as $slot => $vals) {
            if (!isset(self::COMPONENT_ROLES[$slot]) || !is_array($vals)) {
                throw new \InvalidArgumentException("Componente desconocido: $slot.");
            }
            foreach ($vals as $r => $v) {
                if (!in_array($r, self::COMPONENT_ROLES[$slot], true)) {
                    throw new \InvalidArgumentException("Rol no admitido en $slot: $r.");
                }
                if ($v !== '' && $v !== null) {
                    $comp[$slot][$r] = $hex($v, "$slot/$r");
                }
            }
        }
        $t = $d['typography'];
        $ti = (array)($in['typography'] ?? []);
        $fam = static function (mixed $v, ?FontCatalog $fonts): string {
            if ($v === 'system') {
                return 'system';
            }
            if (!is_string($v) || !preg_match('/^[a-z0-9-]{1,40}$/', $v) || ($fonts !== null && $fonts->get($v) === null)) {
                throw new \InvalidArgumentException('Familia tipográfica no disponible en el catálogo local.');
            }
            return $v;
        };
        $num = static function (mixed $v, float $min, float $max, string $what): float|int {
            if (!is_numeric($v) || $v < $min || $v > $max) {
                throw new \InvalidArgumentException("«{$what}» fuera de rango ({$min}–{$max}).");
            }
            return $v + 0;
        };
        $b = (array)($ti['body'] ?? []);
        $t['body']['family'] = $fam($b['family'] ?? $t['body']['family'], $fonts);
        $t['body']['size'] = $num($b['size'] ?? $t['body']['size'], 14, 22, 'Tamaño del texto');
        $t['body']['line'] = $num($b['line'] ?? $t['body']['line'], 1.2, 2.2, 'Interlineado');
        $al = $b['align'] ?? $t['body']['align'];
        if (!isset(self::ALIGN[$al])) {
            throw new \InvalidArgumentException('Alineación no válida.');
        }
        $t['body']['align'] = $al;
        $h = (array)($ti['heading'] ?? []);
        $t['heading']['family'] = $fam($h['family'] ?? $t['heading']['family'], $fonts);
        $hw = (int)($h['weight'] ?? $t['heading']['weight']);
        if (!in_array($hw, [400, 700], true)) {
            throw new \InvalidArgumentException('El peso de los títulos debe ser 400 o 700.');
        }
        $t['heading']['weight'] = $hw;
        $sp = $ti['spacing'] ?? $t['spacing'];
        if (!isset(self::SPACING[$sp])) {
            throw new \InvalidArgumentException('Espaciado no válido.');
        }
        $t['spacing'] = $sp;
        $t['width'] = $num($ti['width'] ?? $t['width'], 30, 80, 'Ancho de contenido');
        return ['colors' => $colors, 'components' => $comp, 'typography' => $t];
    }

    /**
     * CSS del tema: variables y reglas globales. $stacks = pila de fuentes ya resuelta por rol (body/heading).
     * @param array<string,mixed> $theme normalizado @param array{body:string,heading:string} $stacks
     */
    public static function css(array $theme, array $stacks): string
    {
        $c = $theme['colors'];
        $t = $theme['typography'];
        $v = [];
        foreach ($c as $r => $val) {
            $v[] = '--lux-' . str_replace('_', '-', $r) . ':' . $val;
        }
        foreach ($theme['components'] as $slot => $vals) {
            foreach ($vals as $r => $val) {
                $v[] = "--lux-$slot-$r:$val";
            }
        }
        $v[] = '--lux-font-body:' . $stacks['body'];
        $v[] = '--lux-font-heading:' . $stacks['heading'];
        $v[] = '--lux-size:' . $t['body']['size'] . 'px';
        $v[] = '--lux-line:' . $t['body']['line'];
        $v[] = '--lux-gap:' . Theme::SPACING[$t['spacing']] . 'em';
        $v[] = '--lux-width:' . $t['width'] . 'rem';
        $css = ':root{' . implode(';', $v) . "}\n";
        $css .= 'body{margin:0;font-family:var(--lux-font-body);font-size:var(--lux-size);line-height:var(--lux-line);color:var(--lux-text);background:var(--lux-bg);text-align:' . $t['body']['align'] . "}\n";
        $css .= 'h1,h2,h3,h4,h5,h6{font-family:var(--lux-font-heading);font-weight:' . $t['heading']['weight'] . ";line-height:1.25}\n";
        $css .= "p,ul,ol,blockquote{margin:0 0 var(--lux-gap)}\n";
        return $css;
    }
}
