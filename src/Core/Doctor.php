<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

use LuxAeterna\Publishing\Activator;

/** Diagnóstico del entorno. Cada comprobación devuelve ok | warn | fail. */
final class Doctor
{
    public const MIN_PHP = '8.1.0';
    public const REQUIRED_EXT = ['pdo_sqlite', 'mbstring', 'json', 'ctype', 'fileinfo', 'hash', 'sodium'];
    public const DATA_SUBDIRS = ['db', 'originals', 'logs', 'tmp', 'jobs', 'secrets'];

    /** @var list<array{level:string,name:string,message:string}> */
    private array $results = [];

    public function __construct(private readonly string $root, private readonly ?Config $config)
    {
    }

    /** @return list<array{level:string,name:string,message:string}> */
    public function run(bool $http = false): array
    {
        $this->results = [];
        $this->php();
        $this->extensions();
        $this->ini();
        if ($this->config === null) {
            $this->add('fail', 'config', 'No hay configuración válida (config/lux.json). Ejecuta bin/lux install --url=https://tu-dominio');
            return $this->results;
        }
        $this->add('ok', 'config', 'URL base: ' . $this->config->baseUrl());
        if (str_starts_with($this->config->baseUrl(), 'http://')) {
            $this->add('warn', 'https', 'La URL base usa http (solo aceptable en localhost). En producción usa https.');
        }
        $this->directories();
        $this->space();
        $this->symlinks();
        if ($http) {
            $this->exposure();
        }
        return $this->results;
    }

    /** @param list<array{level:string,name:string,message:string}> $results */
    public static function exitCode(array $results): int
    {
        foreach ($results as $r) {
            if ($r['level'] === 'fail') {
                return 1;
            }
        }
        return 0;
    }

    private function add(string $level, string $name, string $message): void
    {
        $this->results[] = ['level' => $level, 'name' => $name, 'message' => $message];
    }

    private function php(): void
    {
        version_compare(PHP_VERSION, self::MIN_PHP, '>=')
            ? $this->add('ok', 'php', 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')')
            : $this->add('fail', 'php', 'PHP ' . PHP_VERSION . ': se requiere ' . self::MIN_PHP . ' o superior.');
    }

    private function extensions(): void
    {
        $missing = array_values(array_filter(self::REQUIRED_EXT, static fn(string $e): bool => !extension_loaded($e)));
        $missing === []
            ? $this->add('ok', 'extensiones', 'Requeridas presentes: ' . implode(', ', self::REQUIRED_EXT))
            : $this->add('fail', 'extensiones', 'Faltan extensiones: ' . implode(', ', $missing));
        if (extension_loaded('gd') && function_exists('imagewebp')) {
            $this->add('ok', 'imagen', 'GD con soporte WebP' . (extension_loaded('imagick') ? ' (Imagick también disponible)' : ''));
        } else {
            $this->add('warn', 'imagen', 'GD con WebP no disponible: será necesario en la Fase 6 (biblioteca multimedia).');
        }
        defined('PASSWORD_ARGON2ID')
            ? $this->add('ok', 'hash', 'Argon2id disponible para contraseñas')
            : $this->add('warn', 'hash', 'Argon2id no disponible; se usará bcrypt.');
    }

    private function ini(): void
    {
        $mem = self::bytes((string)ini_get('memory_limit'));
        if ($mem !== -1 && $mem < 128 * 1048576) {
            $this->add('warn', 'memoria', 'memory_limit=' . ini_get('memory_limit') . ' (<128M): los lotes se reducirán.');
        } else {
            $this->add('ok', 'memoria', 'memory_limit=' . ini_get('memory_limit'));
        }
        $t = (int)ini_get('max_execution_time');
        $this->add('ok', 'tiempo', 'max_execution_time=' . ($t === 0 ? 'sin límite' : $t . 's'));
    }

    private function directories(): void
    {
        foreach (['data' => 'datos privados', 'publish' => 'publicación'] as $key => $label) {
            try {
                $dir = $this->config->path($key);
            } catch (LuxException $e) {
                $this->add('fail', "dir:$key", $e->getMessage());
                continue;
            }
            if (!is_dir($dir)) {
                $this->add('fail', "dir:$key", "No existe el directorio de $label. Ejecuta bin/lux install.");
            } elseif (!is_writable($dir)) {
                $this->add('fail', "dir:$key", "El directorio de $label no es escribible por el usuario actual.");
            } else {
                $this->add('ok', "dir:$key", "Directorio de $label correcto");
            }
        }
        $data = $this->config->path('data');
        $docRoot = realpath($this->root . '/public');
        $real = realpath($data);
        if ($real !== false && $docRoot !== false && str_starts_with($real . '/', $docRoot . '/')) {
            $this->add('fail', 'privado', 'El directorio de datos está dentro de public/.');
        }
        if (is_dir($data)) {
            $missing = array_filter(self::DATA_SUBDIRS, static fn(string $d): bool => !is_dir($data . '/' . $d));
            if ($missing !== []) {
                $this->add('warn', 'datos', 'Faltan subdirectorios en datos: ' . implode(', ', $missing));
            }
        }
    }

    private function space(): void
    {
        $free = Fs::freeSpace($this->config->path('publish'));
        if ($free === null) {
            $this->add('warn', 'disco', 'No se pudo medir el espacio libre.');
            return;
        }
        $mb = intdiv($free, 1048576);
        $this->add($mb < 50 ? 'fail' : ($mb < 500 ? 'warn' : 'ok'), 'disco', "Espacio libre: {$mb} MB");
    }

    private function symlinks(): void
    {
        $dir = $this->config->path('publish');
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $ok = Activator::supportsSymlinks($dir);
        $mode = (string)$this->config->get('publishing.activation', 'auto');
        $this->add('ok', 'activación', $ok
            ? 'Enlaces simbólicos disponibles: activación por enlace (modo configurado: ' . $mode . ')'
            : 'Sin enlaces simbólicos: la activación usará intercambio de directorios (modo configurado: ' . $mode . ')');
        if (!$ok && $mode === 'symlink') {
            $this->add('fail', 'activación', 'activation=symlink pero el sistema no admite enlaces simbólicos.');
        }
    }

    /** Escribe un archivo canario en el directorio privado y comprueba que no es accesible por HTTP. */
    private function exposure(): void
    {
        $data = $this->config->path('data');
        if (!is_dir($data) || !is_writable($data)) {
            return;
        }
        $name = '.canary-' . bin2hex(random_bytes(6)) . '.txt';
        file_put_contents($data . '/' . $name, 'canary');
        $leaks = [];
        $answered = 0;
        $segments = array_unique([basename($data), 'var', 'var/db']);
        try {
            foreach ($segments as $seg) {
                $url = $this->config->baseUrl() . '/' . $seg . '/' . $name;
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'method' => 'GET']]);
                $body = @file_get_contents($url, false, $ctx);
                if (isset($http_response_header)) {
                    $answered++;
                }
                if ($body === 'canary') {
                    $leaks[] = $url;
                }
            }
        } finally {
            @unlink($data . '/' . $name);
        }
        if ($leaks === [] && $answered === 0) {
            $this->add('warn', 'exposición', 'No se pudo comprobar: la URL base no respondió. Publica el sitio y repite bin/lux doctor --http.');
            return;
        }
        $leaks === []
            ? $this->add('ok', 'exposición', 'El directorio privado no es accesible por HTTP (canario no servido)')
            : $this->add('fail', 'exposición', 'DATOS PRIVADOS ACCESIBLES por HTTP: ' . implode(', ', $leaks));
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
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
}
