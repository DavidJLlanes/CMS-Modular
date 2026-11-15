<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

/**
 * Configuración en JSON (config/lux.json). JSON y no PHP: un archivo de configuración no debe poder ejecutar código.
 * Las rutas relativas se resuelven respecto a la raíz de la aplicación.
 */
final class Config
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data, public readonly string $root)
    {
    }

    public static function load(string $root, ?string $file = null): self
    {
        $file ??= $root . '/config/lux.json';
        if (!is_file($file)) {
            throw new LuxException('No hay configuración. Ejecuta: bin/lux install --url=https://tu-dominio', "Falta $file");
        }
        $raw = file_get_contents($file);
        try {
            $data = json_decode((string)$raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LuxException('La configuración no es JSON válido.', $e->getMessage(), 1, $e);
        }
        if (!is_array($data)) {
            throw new LuxException('La configuración debe ser un objeto JSON.');
        }
        return self::fromArray($data, $root);
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $root): self
    {
        if (($data['schema'] ?? null) !== self::SCHEMA_VERSION) {
            throw new LuxException('Versión de configuración no soportada (se esperaba schema ' . self::SCHEMA_VERSION . ').');
        }
        $data['site']['base_url'] = self::normalizeBaseUrl((string)($data['site']['base_url'] ?? ''));
        return new self($data, rtrim($root, '/'));
    }

    /**
     * URL base explícita. Decisión D3: solo raíz de dominio, sin subcarpeta. Devuelve sin barra final.
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $p = parse_url(trim($url));
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            throw new LuxException('La URL base no es válida. Ejemplo: https://luxaeterna.es');
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if (!in_array($scheme, ['https', 'http'], true) || ($scheme === 'http' && !$local)) {
            throw new LuxException('La URL base debe usar https (http solo en localhost).');
        }
        $path = $p['path'] ?? '';
        if (($path !== '' && $path !== '/') || isset($p['query']) || isset($p['fragment']) || isset($p['user'])) {
            throw new LuxException('La URL base debe ser solo el dominio (sin subcarpeta, parámetros ni credenciales).');
        }
        return $scheme . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    public function baseUrl(): string
    {
        return (string)$this->get('site.base_url');
    }

    /** Resuelve una ruta de configuración (absoluta o relativa a la raíz). */
    public function path(string $key): string
    {
        $value = (string)$this->get('paths.' . $key, '');
        if ($value === '') {
            throw new LuxException("Falta paths.$key en la configuración.");
        }
        return $value[0] === '/' ? rtrim($value, '/') : $this->root . '/' . rtrim($value, '/');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
