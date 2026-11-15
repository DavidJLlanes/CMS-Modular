<?php
declare(strict_types=1);

/** Runner mínimo sin dependencias (R03): ejecuta tests/*Test.php. Uso: php tests/run.php [filtro] */

$root = dirname(__DIR__);
require $root . '/src/Core/Autoloader.php';
LuxAeterna\Core\Autoloader::register($root . '/src');

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var array<string,callable> */
    public static array $tests = [];

    public static function test(string $name, callable $fn): void
    {
        self::$tests[$name] = $fn;
    }

    public static function ok(bool $cond, string $msg = ''): void
    {
        if (!$cond) {
            throw new RuntimeException('Aserción falsa' . ($msg !== '' ? ": $msg" : ''));
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(($msg !== '' ? "$msg: " : '') . 'esperado ' . var_export($expected, true) . ', obtenido ' . var_export($actual, true));
        }
    }

    /** @param class-string<Throwable> $class */
    public static function throws(string $class, callable $fn, string $contains = ''): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            self::ok($e instanceof $class, 'excepción de tipo ' . get_class($e));
            self::ok($contains === '' || str_contains($e->getMessage(), $contains), "mensaje '{$e->getMessage()}' debe contener '$contains'");
            return;
        }
        throw new RuntimeException("Se esperaba $class");
    }

    public static function tmpDir(): string
    {
        $d = sys_get_temp_dir() . '/lux-test-' . bin2hex(random_bytes(4));
        mkdir($d, 0755, true);
        register_shutdown_function(static function () use ($d): void {
            exec('rm -rf ' . escapeshellarg($d));
        });
        return $d;
    }
}

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}
foreach (T::$tests as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $fn();
        T::$pass++;
        echo "OK    $name\n";
    } catch (Throwable $e) {
        T::$fail++;
        echo "FALLO $name — " . $e->getMessage() . "\n";
    }
}
echo sprintf("\n%d correctas, %d con fallo\n", T::$pass, T::$fail);
exit(T::$fail === 0 ? 0 : 1);
