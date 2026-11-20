<?php
declare(strict_types=1);

namespace CMSModular\Media;

use CMSModular\Core\LuxException;

/**
 * Procesador de imágenes con GD: decodifica el original UNA vez, aplica la orientación y genera todos los derivados
 * WebP pedidos. Al volver a codificar se descartan los metadatos (EXIF, GPS, miniaturas incrustadas). El original no
 * se toca. GD no convierte perfiles ICC: se avisa en la ingesta (ver ImageInspector).
 */
final class DerivativeBuilder
{
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    /**
     * @param list<array{w:int,h:int,fit:string,fx:int,fy:int,q:int,v:int}> $recipes
     * @return list<array{recipe:array<string,int|string>,data:string,width:int,height:int}> en el mismo orden
     */
    public static function render(string $file, string $mime, int $orientation, array $recipes): array
    {
        if (!self::available()) {
            throw new LuxException('Este servidor no tiene GD con soporte WebP: no se pueden generar imágenes.');
        }
        $src = self::load($file, $mime);
        try {
            $src = self::orient($src, $orientation);
            $sw = imagesx($src);
            $sh = imagesy($src);
            $out = [];
            foreach ($recipes as $r) {
                $out[] = self::one($src, $sw, $sh, $r);
            }
            return $out;
        } finally {
            imagedestroy($src);
        }
    }

    private static function load(string $file, string $mime): \GdImage
    {
        ImageInspector::checkMemory(...array_slice((array)(@getimagesize($file) ?: [1, 1]), 0, 2));
        $im = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png' => @imagecreatefrompng($file),
            'image/webp' => @imagecreatefromwebp($file),
            'image/gif' => @imagecreatefromgif($file),
            default => false,
        };
        if ($im === false) {
            throw new LuxException('No se pudo decodificar la imagen: el archivo está dañado o no es compatible.');
        }
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        return $im;
    }

    /** Aplica la orientación EXIF (1–8). Pública para poder probarla pixel a pixel. */
    public static function orient(\GdImage $im, int $o): \GdImage
    {
        $flip = match ($o) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };
        if ($flip !== null) {
            imageflip($im, $flip);
        }
        $angle = match ($o) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rot = imagerotate($im, $angle, 0);
            if ($rot === false) {
                throw new LuxException('No se pudo girar la imagen.');
            }
            imagedestroy($im);
            return $rot;
        }
        return $im;
    }

    /** @param array{w:int,h:int,fit:string,fx:int,fy:int,q:int,v:int} $r @return array{recipe:array<string,int|string>,data:string,width:int,height:int} */
    private static function one(\GdImage $src, int $sw, int $sh, array $r): array
    {
        [$tw, $th] = Recipe::dims($r, $sw, $sh);
        $cx = 0;
        $cy = 0;
        $cw = $sw;
        $ch = $sh;
        if ($r['fit'] === 'cover') {
            $ratio = $tw / $th;
            if ($sw / $sh > $ratio) { // más ancha que el objetivo: recorta a los lados según el foco horizontal
                $cw = (int)round($sh * $ratio);
                $cx = (int)round(($sw - $cw) * $r['fx'] / 100);
            } else {
                $ch = (int)round($sw / $ratio);
                $cy = (int)round(($sh - $ch) * $r['fy'] / 100);
            }
        }
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, (int)imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $tw, $th, max(1, $cw), max(1, $ch));
        ob_start();
        $ok = imagewebp($dst, null, $r['q']);
        $data = (string)ob_get_clean();
        imagedestroy($dst);
        if (!$ok || $data === '') {
            throw new LuxException('No se pudo codificar el derivado WebP.');
        }
        return ['recipe' => $r, 'data' => $data, 'width' => $tw, 'height' => $th];
    }
}
