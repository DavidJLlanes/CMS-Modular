<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

/** Utilidades de sistema de archivos. Nunca sigue enlaces simbólicos al borrar. */
final class Fs
{
    public static function ensureDir(string $dir, int $mode = 0750): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new LuxException('No se pudo crear un directorio necesario.', "mkdir $dir");
        }
    }

    public static function atomicWrite(string $file, string $content, int $mode = 0640): void
    {
        self::ensureDir(dirname($file));
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new LuxException('No se pudo escribir un archivo.', "write $tmp");
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new LuxException('No se pudo guardar un archivo.', "rename $tmp -> $file");
        }
    }

    /** Borra un árbol sin seguir enlaces simbólicos y solo si está dentro de $within. */
    public static function removeTree(string $path, string $within): void
    {
        $realWithin = realpath($within);
        $parent = realpath(dirname($path));
        if ($realWithin === false || $parent === false || strncmp($parent . '/', $realWithin . '/', strlen($realWithin) + 1) !== 0) {
            throw new LuxException('Borrado fuera del área permitida.', "removeTree $path within $within");
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child, $within);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    /** Espacio libre en bytes del volumen que contiene $path (o null si no se puede saber). */
    public static function freeSpace(string $path): ?int
    {
        $v = @disk_free_space(is_dir($path) ? $path : dirname($path));
        return $v === false ? null : (int)$v;
    }
}
