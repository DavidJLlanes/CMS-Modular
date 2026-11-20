<?php
declare(strict_types=1);

namespace CMSModular\Content;

use CMSModular\Core\LuxException;

/**
 * Texto con formato en línea como estructura (nunca HTML): lista de tramos `{t, b, i, s, u, sub, sup, c, a}`.
 * Se valida con lista blanca y se escribe HTML semántico escapado. Un tramo sin formato se guarda como texto plano.
 */
final class Inline
{
    public const MARKS = ['b', 'i', 's', 'u', 'sub', 'sup'];
    /** Colores del tema; cualquier otro valor debe ser #rrggbb. */
    public const ROLES = ['text', 'muted', 'primary', 'link'];
    public const MAX_RUNS = 400;
    private const TAGS = ['b' => 'strong', 'i' => 'em', 's' => 's', 'u' => 'u', 'sub' => 'sub', 'sup' => 'sup'];

    /**
     * @param mixed $runs @return list<array<string,mixed>> tramos normalizados (los adyacentes con el mismo formato se unen)
     */
    public static function validate(mixed $runs, int $n, int $maxChars = 20000): array
    {
        if (is_string($runs)) {
            $runs = [['t' => $runs]];
        }
        if (!is_array($runs) || count($runs) > self::MAX_RUNS) {
            throw new LuxException("Bloque $n: el texto con formato no es válido o es demasiado largo.");
        }
        $out = [];
        $chars = 0;
        foreach (array_values($runs) as $r) {
            if (!is_array($r) || !is_string($r['t'] ?? null)) {
                throw new LuxException("Bloque $n: tramo de texto no válido.");
            }
            $t = $r['t'];
            $chars += mb_strlen($t);
            if ($chars > $maxChars || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $t)) {
                throw new LuxException("Bloque $n: texto no válido o demasiado largo (máx. $maxChars caracteres).");
            }
            if ($t === '') {
                continue;
            }
            $run = ['t' => str_replace(["\r\n", "\r"], "\n", $t)];
            foreach (self::MARKS as $m) {
                if (!empty($r[$m])) {
                    $run[$m] = 1;
                }
            }
            if (isset($run['sub'], $run['sup'])) {
                unset($run['sup']);
            }
            if (isset($r['c']) && $r['c'] !== '' && $r['c'] !== null) {
                $run['c'] = self::color($r['c'], $n);
            }
            if (isset($r['a']) && $r['a'] !== '' && $r['a'] !== null) {
                $run['a'] = self::link($r['a'], $n);
            }
            $last = count($out) - 1;
            if ($last >= 0 && self::sameFormat($out[$last], $run)) {
                $out[$last]['t'] .= $run['t'];
            } else {
                $out[] = $run;
            }
        }
        return $out;
    }

    /** ¿Tiene algún formato (marcas, color o enlace)? @param list<array<string,mixed>> $runs */
    public static function formatted(array $runs): bool
    {
        foreach ($runs as $r) {
            if (count($r) > 1) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $runs */
    public static function plain(array $runs): string
    {
        return implode('', array_column($runs, 't'));
    }

    /** Valida un color: rol del tema o #rrggbb (se guarda en minúsculas). */
    public static function color(mixed $c, int $n): string
    {
        if (is_string($c) && in_array($c, self::ROLES, true)) {
            return $c;
        }
        if (is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
            return strtolower($c);
        }
        throw new LuxException("Bloque $n: color no válido (usa un color del tema o #rrggbb).");
    }

    /** Enlaces permitidos: ruta interna, ancla, https/http, mailto y tel. Todo lo demás (javascript:, data:…) se rechaza. */
    public static function link(mixed $u, int $n): string
    {
        $u = is_string($u) ? trim($u) : '';
        $ok = $u !== '' && mb_strlen($u) <= 500 && !preg_match('/[\x00-\x20\x7F"<>\\\\`]/', $u)
            && (preg_match('#^/(?!/)#', $u) || $u[0] === '#' || preg_match('#^https?://[^/?\#]+#i', $u) || preg_match('/^(?:mailto|tel):[^\s]+$/i', $u));
        if (!$ok) {
            throw new LuxException("Bloque $n: enlace no permitido (usa una ruta del sitio, https://, mailto: o tel:).");
        }
        return $u;
    }

    /** CSS de clase para un color de tramo o bloque (nunca estilos en línea: la CSP los prohíbe). */
    public static function colorClass(string $c): string
    {
        return 'lux-c-' . (str_starts_with($c, '#') ? substr($c, 1) : $c);
    }

    /** @param list<array<string,mixed>> $runs */
    public static function html(array $runs): string
    {
        $h = '';
        foreach ($runs as $r) {
            $s = nl2br(htmlspecialchars((string)$r['t'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            foreach (['sub', 'sup', 'u', 's', 'i', 'b'] as $m) {
                if (!empty($r[$m])) {
                    $s = '<' . self::TAGS[$m] . '>' . $s . '</' . self::TAGS[$m] . '>';
                }
            }
            if (isset($r['c'])) {
                $s = '<span class="' . self::colorClass((string)$r['c']) . '">' . $s . '</span>';
            }
            if (isset($r['a'])) {
                $a = (string)$r['a'];
                $s = '<a href="' . htmlspecialchars($a, ENT_QUOTES, 'UTF-8') . '"' . (preg_match('#^https?://#i', $a) ? ' rel="noopener noreferrer"' : '') . '>' . $s . '</a>';
            }
            $h .= $s;
        }
        return $h;
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private static function sameFormat(array $a, array $b): bool
    {
        unset($a['t'], $b['t']);
        ksort($a);
        ksort($b);
        return $a === $b;
    }
}
