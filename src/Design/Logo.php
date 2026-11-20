<?php
declare(strict_types=1);

namespace CMSModular\Design;

/**
 * Logo del sitio: texto o imagen de components/logos/. El tamaño se fija por ubicación y dispositivo SOLO como ancho;
 * el alto sale de la proporción real de la imagen, de modo que nunca se deforma.
 */
final class Logo
{
    public const PLACES = ['topbar' => 'Barra superior', 'header' => 'Cabecera', 'hero' => 'Hero', 'footer' => 'Pie'];
    private const EXT = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return ['mode' => 'text', 'text' => '', 'image' => '', 'alt' => '',
            'sizes' => ['topbar' => [24, 24], 'header' => [160, 120], 'hero' => [200, 140], 'footer' => [140, 110]]];
    }

    /** @param array<string,mixed> $in @return array<string,mixed> @throws \InvalidArgumentException */
    public static function normalize(array $in, ?string $dir): array
    {
        $o = self::defaults();
        $mode = $in['mode'] ?? $o['mode'];
        if (!in_array($mode, ['text', 'image'], true)) {
            throw new \InvalidArgumentException('Tipo de logo no válido.');
        }
        $o['mode'] = $mode;
        foreach (['text' => 80, 'alt' => 120] as $k => $max) {
            $v = trim((string)($in[$k] ?? ''));
            if (mb_strlen($v) > $max || preg_match('/[\x00-\x1F]/', $v)) {
                throw new \InvalidArgumentException("Logo: «$k» no válido.");
            }
            $o[$k] = $v;
        }
        $img = (string)($in['image'] ?? '');
        if ($img !== '') {
            if ($dir === null || self::inspect($dir, $img) === null) {
                throw new \InvalidArgumentException('La imagen del logo no es válida o no está en components/logos/.');
            }
            $o['image'] = $img;
        }
        if ($mode === 'image' && $o['image'] === '') {
            throw new \InvalidArgumentException('Elige una imagen para el logo.');
        }
        foreach ((array)($in['sizes'] ?? []) as $place => $s) {
            if (!isset(self::PLACES[$place]) || !is_array($s) || count($s) !== 2) {
                throw new \InvalidArgumentException('Tamaño de logo no válido.');
            }
            [$d, $m] = array_values($s);
            if (!is_numeric($d) || !is_numeric($m) || $d < 16 || $d > 600 || $m < 16 || $m > $d) {
                throw new \InvalidArgumentException('Tamaño de logo: entre 16 y 600 px, y el móvil no puede superar al escritorio.');
            }
            $o['sizes'][$place] = [(int)$d, (int)$m];
        }
        return $o;
    }

    /** @return list<string> imágenes válidas disponibles */
    public static function available(string $dir): array
    {
        $out = [];
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f[0] !== '.' && self::inspect($dir, $f) !== null) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /** @return array{w:int,h:int,mime:string,hash:string,ext:string}|null */
    public static function inspect(string $dir, string $file): ?array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,60}\.[A-Za-z]{3,4}$/', $file) || !is_file("$dir/$file") || is_link("$dir/$file") || filesize("$dir/$file") > 524288) {
            return null;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::EXT[$ext])) {
            return null;
        }
        $bin = (string)file_get_contents("$dir/$file");
        if ($ext === 'svg') {
            if (preg_match('/<\s*(script|foreignObject|iframe|image)\b|\son[a-z]+\s*=|(?:href|src)\s*=\s*["\']\s*(?:https?:)?\/\/|javascript:|<!ENTITY/i', $bin)) {
                return null;
            }
            if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $bin, $m)) {
                [$w, $h] = [(float)$m[1], (float)$m[2]];
            } elseif (preg_match('/<svg[^>]*\swidth\s*=\s*["\']([\d.]+)(?:px)?["\'][^>]*\sheight\s*=\s*["\']([\d.]+)(?:px)?["\']/i', $bin, $m)) {
                [$w, $h] = [(float)$m[1], (float)$m[2]];
            } else {
                return null;
            }
            if ($w <= 0 || $h <= 0) {
                return null;
            }
            return ['w' => (int)round($w * 1000), 'h' => (int)round($h * 1000), 'mime' => self::EXT[$ext], 'hash' => substr(hash('sha256', $bin), 0, 10), 'ext' => 'svg'];
        }
        $i = @getimagesizefromstring($bin);
        if ($i === false || $i[0] < 1 || $i[1] < 1 || $i[0] > 4000 || $i[1] > 4000 || !in_array($i['mime'], ['image/png', 'image/webp', 'image/jpeg'], true)) {
            return null;
        }
        return ['w' => (int)$i[0], 'h' => (int)$i[1], 'mime' => $i['mime'], 'hash' => substr(hash('sha256', $bin), 0, 10), 'ext' => $ext === 'jpeg' ? 'jpg' : $ext];
    }
}
