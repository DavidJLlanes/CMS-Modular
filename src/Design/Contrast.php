<?php
declare(strict_types=1);

namespace CMSModular\Design;

/** Contraste WCAG 2.x entre colores #rrggbb. */
final class Contrast
{
    public static function luminance(string $hex): float
    {
        $c = array_map(static function (string $h): float {
            $v = hexdec($h) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, [substr($hex, 1, 2), substr($hex, 3, 2), substr($hex, 5, 2)]);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Pares de lectura con su ratio. AA exige 4,5:1 para texto normal.
     * @param array<string,string> $c roles de color @return list<array{pair:string,ratio:float,min:float,ok:bool}>
     */
    public static function audit(array $c, array $comp = []): array
    {
        $pairs = [
            'Texto sobre fondo' => [$c['text'], $c['bg']],
            'Texto sobre superficie' => [$c['text'], $c['surface']],
            'Texto secundario sobre fondo' => [$c['muted'], $c['bg']],
            'Texto secundario sobre superficie' => [$c['muted'], $c['surface']],
            'Enlace sobre fondo' => [$c['link'], $c['bg']],
            'Enlace sobre superficie' => [$c['link'], $c['surface']],
            'Texto sobre color principal' => [$c['primary_text'], $c['primary']],
        ];
        foreach (['header' => 'cabecera', 'topbar' => 'barra superior', 'hero' => 'hero', 'footer' => 'pie'] as $slot => $label) {
            $o = $comp[$slot] ?? [];
            if ($o === []) {
                continue;
            }
            $bg = $o['bg'] ?? $c['surface'];
            if (isset($o['text'])) {
                $pairs["Texto de $label"] = [$o['text'], $bg];
            }
            if (isset($o['link'])) {
                $pairs["Enlace de $label"] = [$o['link'], $bg];
            }
        }
        $out = [];
        foreach ($pairs as $name => [$fg, $bg]) {
            $r = round(self::ratio($fg, $bg), 2);
            $out[] = ['pair' => $name, 'ratio' => $r, 'min' => 4.5, 'ok' => $r >= 4.5];
        }
        return $out;
    }
}
