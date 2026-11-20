<?php
declare(strict_types=1);

namespace CMSModular\Core;

/** Autoloader PSR-4 mínimo para el espacio de nombres CMSModular\ (sin Composer en producción). */
final class Autoloader
{
    public static function register(string $srcDir): void
    {
        $srcDir = rtrim($srcDir, '/');
        spl_autoload_register(static function (string $class) use ($srcDir): void {
            $prefix = 'CMSModular\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $srcDir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
