<?php
declare(strict_types=1);

namespace LuxAeterna\Media;

/**
 * Receta de un derivado: dimensiones, recorte, foco, calidad y versión del procesado. La clave del derivado es
 * la huella del original más la receta canónica: si ninguna cambia, el archivo existente sigue vigente.
 */
final class Recipe
{
    /** Súbelo cuando cambie el algoritmo de procesado: invalida todos los derivados. */
    public const VERSION = 1;

    /**
     * @param string $fit "inside" (cabe en w×h sin recortar) o "cover" (llena w×h recortando según el foco)
     * @return array{w:int,h:int,fit:string,fx:int,fy:int,q:int,v:int}
     */
    public static function make(int $w, int $h = 0, string $fit = 'inside', int $fx = 50, int $fy = 50, int $q = 82): array
    {
        $fit = $fit === 'cover' && $h > 0 ? 'cover' : 'inside';
        return [
            'w' => max(16, min(16000, $w)), 'h' => max(0, min(16000, $h)), 'fit' => $fit,
            'fx' => $fit === 'cover' ? max(0, min(100, $fx)) : 50, 'fy' => $fit === 'cover' ? max(0, min(100, $fy)) : 50,
            'q' => max(1, min(100, $q)), 'v' => self::VERSION,
        ];
    }

    /** @param array<string,int|string> $recipe */
    public static function key(string $originalSha, array $recipe): string
    {
        ksort($recipe);
        return substr(hash('sha256', $originalSha . '|' . json_encode($recipe)), 0, 16);
    }

    /**
     * Dimensiones resultantes sin ampliar nunca el original (se calculan igual al planificar y al procesar).
     * @param array{w:int,h:int,fit:string} $r @return array{0:int,1:int}
     */
    public static function dims(array $r, int $srcW, int $srcH): array
    {
        if ($r['fit'] === 'cover') {
            $scale = min(1.0, $srcW / $r['w'], $srcH / $r['h']);
            return [max(1, (int)round($r['w'] * $scale)), max(1, (int)round($r['h'] * $scale))];
        }
        $scale = min(1.0, $r['w'] / $srcW, $r['h'] > 0 ? $r['h'] / $srcH : 1.0);
        return [max(1, (int)round($srcW * $scale)), max(1, (int)round($srcH * $scale))];
    }

    /**
     * Anchuras que merece la pena generar: las de la escalera menores que el límite útil y el propio límite
     * (nunca más que el original ni más del doble del tamaño en pantalla).
     * @return list<int>
     */
    public static function widths(int $origWidth, int $maxWidth, int $displayPx): array
    {
        $cap = max(16, min($origWidth, $maxWidth, $displayPx * 2));
        $out = [];
        foreach (MediaSettings::LADDER as $w) {
            if ($w < $cap - 80) {
                $out[] = $w;
            }
        }
        $out[] = $cap;
        return array_slice($out, -5);
    }
}
