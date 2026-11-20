<?php
declare(strict_types=1);

namespace CMSModular\Publishing;

/**
 * Reglas HTTP de redirección permanentes (301) que acompañan a cada versión.
 *  - Nginx: archivo con un `location = /ruta/ { return 301 /destino/; }` por redirección. Lo aplica un servicio con
 *    privilegios que SOLO acepta esa forma exacta (ver deploy/lux-apply-rules.sh).
 *  - Apache/LiteSpeed: `.htaccess` dentro de la propia versión, que viaja con ella (activación y reversión atómicas).
 */
final class RuleBuilder
{
    private const PATH = '#^/(?:[a-z0-9]+(?:-[a-z0-9]+)*/)*$#';

    /** @param list<array{from:string,to:string}> $redirects @return list<array{from:string,to:string}> solo las válidas, ordenadas */
    public static function clean(array $redirects): array
    {
        $out = [];
        foreach ($redirects as $r) {
            if ($r['from'] !== $r['to'] && preg_match(self::PATH, $r['from']) && preg_match(self::PATH, $r['to']) && $r['from'] !== '/') {
                $out[$r['from']] = ['from' => $r['from'], 'to' => $r['to']];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** @param list<array{from:string,to:string}> $redirects */
    public static function nginx(array $redirects): string
    {
        $s = "# Generado por CMS-Modular. No editar a mano.\n";
        foreach (self::clean($redirects) as $r) {
            $s .= "location = {$r['from']} { return 301 {$r['to']}; }\n";
        }
        return $s;
    }

    /** @param list<array{from:string,to:string}> $redirects */
    public static function apache(array $redirects, string $base = ''): string
    {
        $s = ($base !== '' ? rtrim($base) . "\n\n" : '') . "# Redirecciones (generado por CMS-Modular)\n<IfModule mod_alias.c>\n";
        foreach (self::clean($redirects) as $r) {
            $s .= "RedirectMatch 301 \"^" . str_replace('-', '\\-', $r['from']) . "$\" \"{$r['to']}\"\n";
        }
        return $s . "</IfModule>\n";
    }
}
