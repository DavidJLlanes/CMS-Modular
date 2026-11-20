<?php
declare(strict_types=1);

namespace CMSModular\Media;

use CMSModular\Core\LuxException;

/**
 * Valida un archivo subido por su CONTENIDO (no por nombre ni tipo declarado): formato real, tamaño, píxeles y
 * memoria necesaria para decodificarlo con seguridad. No decodifica la imagen completa: lee cabeceras.
 */
final class ImageInspector
{
    public const TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
    ];

    /**
     * @return array{mime:string,ext:string,width:int,height:int,orientation:int,has_alpha:bool,bytes:int,notes:list<string>}
     */
    public static function inspect(string $file, MediaSettings $s): array
    {
        $bytes = @filesize($file);
        if (!is_file($file) || $bytes === false || $bytes === 0) {
            throw new LuxException('El archivo está vacío o no se pudo leer.');
        }
        if ($bytes > $s->maxUploadBytes()) {
            throw new LuxException('El archivo pesa ' . round($bytes / 1048576, 1) . ' MB y el máximo es ' . round($s->maxUploadBytes() / 1048576) . ' MB.');
        }
        $info = @getimagesize($file);
        if ($info === false || !isset(self::TYPES[$info[2]])) {
            throw new LuxException('Formato no admitido. Sube JPEG, PNG, WebP o GIF sin animación.');
        }
        [$mime, $ext] = self::TYPES[$info[2]];
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        if ($fi->file($file) !== $mime) {
            throw new LuxException('El contenido del archivo no coincide con un formato de imagen válido.');
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < 1 || $h < 1) {
            throw new LuxException('La imagen no tiene dimensiones válidas.');
        }
        if ($w > $s->maxSide() || $h > $s->maxSide()) {
            throw new LuxException("La imagen mide {$w}×{$h} px y el lado máximo es {$s->maxSide()} px.");
        }
        if ($w * $h > $s->maxPixels()) {
            throw new LuxException('La imagen tiene ' . round($w * $h / 1000000, 1) . ' megapíxeles y el máximo es ' . round($s->maxPixels() / 1000000) . '. Reduce su tamaño antes de subirla.');
        }
        self::checkMemory($w, $h);
        $head = (string)file_get_contents($file, false, null, 0, 65536);
        $notes = [];
        if (self::animated($info[2], $file, $head)) {
            throw new LuxException('Las imágenes animadas no se admiten. Sube una imagen fija.');
        }
        $orientation = 1;
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $ex = @exif_read_data($file, 'IFD0');
            $orientation = is_array($ex) ? max(1, min(8, (int)($ex['Orientation'] ?? 1))) : 1;
            if ($orientation >= 5) {
                [$w, $h] = [$h, $w];
            }
            if ($orientation > 1) {
                $notes[] = 'Se ha corregido la orientación guardada en los metadatos.';
            }
        }
        if (self::foreignProfile($info[2], $head)) {
            $notes[] = 'La imagen incluye un perfil de color que no es sRGB. Se publica como sRGB; revisa que los colores sigan bien.';
        }
        return ['mime' => $mime, 'ext' => $ext, 'width' => $w, 'height' => $h, 'orientation' => $orientation, 'has_alpha' => self::alpha($info[2], $head), 'bytes' => $bytes, 'notes' => $notes];
    }

    /** Memoria estimada para decodificar (GD usa 4 bytes por píxel y se necesita margen para el derivado). */
    public static function checkMemory(int $w, int $h): void
    {
        $limit = self::memoryLimit();
        if ($limit < 0) {
            return;
        }
        $need = (int)($w * $h * 4 * 1.25) + 24 * 1048576;
        $free = $limit - memory_get_usage(true);
        if ($need > $free) {
            throw new LuxException('La imagen es demasiado grande para la memoria de este servidor (' . round($limit / 1048576) . ' MB de PHP). Redúcela o aumenta memory_limit.', "need=$need free=$free");
        }
    }

    public static function memoryLimit(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private static function animated(int $type, string $file, string $head): bool
    {
        if ($type === IMAGETYPE_GIF) {
            return self::gifFrames((string)file_get_contents($file)) > 1;
        }
        if ($type === IMAGETYPE_PNG) {
            return str_contains(substr($head, 0, 4096), 'acTL');
        }
        if ($type === IMAGETYPE_WEBP) {
            return str_contains(substr($head, 0, 64), 'VP8X') && strlen($head) > 20 && (ord($head[20]) & 0x02) !== 0;
        }
        return false;
    }

    /** Cuenta los fotogramas de un GIF recorriendo sus bloques (no busca patrones de bytes). */
    private static function gifFrames(string $g): int
    {
        $n = strlen($g);
        if ($n < 13) {
            return 0;
        }
        $p = 13 + (ord($g[10]) & 0x80 ? 3 * (1 << ((ord($g[10]) & 7) + 1)) : 0);
        $frames = 0;
        while ($p < $n && $g[$p] !== ';') {
            if ($g[$p] === '!') { // extensión: etiqueta + subbloques
                $p += 2;
            } elseif ($g[$p] === ',') { // imagen
                $frames++;
                if ($frames > 1 || $p + 10 > $n) {
                    return $frames;
                }
                $flags = ord($g[$p + 9]);
                $p += 10 + ($flags & 0x80 ? 3 * (1 << (($flags & 7) + 1)) : 0) + 1;
            } else {
                return $frames;
            }
            while ($p < $n && ($len = ord($g[$p])) > 0) { // subbloques hasta el terminador
                $p += $len + 1;
            }
            $p++;
        }
        return $frames;
    }

    private static function alpha(int $type, string $head): bool
    {
        return match ($type) {
            IMAGETYPE_PNG => strlen($head) > 25 && in_array(ord($head[25]), [4, 6], true) || str_contains(substr($head, 0, 4096), 'tRNS'),
            IMAGETYPE_WEBP => (str_contains(substr($head, 0, 64), 'VP8X') && strlen($head) > 20 && (ord($head[20]) & 0x10) !== 0) || str_contains(substr($head, 0, 32), 'VP8L'),
            IMAGETYPE_GIF => str_contains(substr($head, 0, 2048), "\x21\xF9\x04\x01") || (bool)preg_match('/\x21\xF9\x04[\x01\x05\x09\x0D]/', substr($head, 0, 2048)),
            default => false,
        };
    }

    /** Detecta un perfil ICC incrustado que no sea sRGB (GD no convierte perfiles). */
    private static function foreignProfile(int $type, string $head): bool
    {
        if ($type === IMAGETYPE_JPEG) {
            $p = strpos($head, 'ICC_PROFILE');
            if ($p === false) {
                return false;
            }
            return stripos(substr($head, $p, 4096), 'sRGB') === false;
        }
        if ($type === IMAGETYPE_PNG) {
            $p = strpos($head, 'iCCP');
            if ($p === false) {
                return false;
            }
            return stripos(substr($head, $p, 64), 'sRGB') === false && !str_contains(substr($head, 0, 2048), 'sRGB');
        }
        return false;
    }
}
