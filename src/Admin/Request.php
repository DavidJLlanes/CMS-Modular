<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

final class Request
{
    /**
     * @param array<string,string> $query
     * @param array<string,mixed>  $post
     * @param array<string,string> $cookies
     * @param array<string,string> $headers cabeceras en minúsculas
     * @param array<string,list<array{name:string,tmp_name:string,size:int,error:int}>> $files archivos subidos por campo
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly string $remoteAddr = '0.0.0.0',
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)parse_url($uri, PHP_URL_PATH);
        // La query se lee de REQUEST_URI (siempre íntegra), no de $_GET: una redirección interna de Nginx puede perderla.
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            rawurldecode($path),
            array_filter($query, 'is_string'),
            $_POST,
            array_filter($_COOKIE, 'is_string'),
            $headers,
            (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            self::uploads()
        );
    }

    /** Solo archivos realmente subidos por HTTP (is_uploaded_file), normalizados a una lista por campo. @return array<string,list<array{name:string,tmp_name:string,size:int,error:int}>> */
    private static function uploads(): array
    {
        $out = [];
        foreach ($_FILES as $field => $f) {
            if (!is_array($f) || !isset($f['tmp_name'])) {
                continue;
            }
            foreach ((array)$f['tmp_name'] as $i => $tmp) {
                $err = (int)(is_array($f['error']) ? $f['error'][$i] : $f['error']);
                if (!is_string($tmp) || ($err === UPLOAD_ERR_OK && !is_uploaded_file($tmp))) {
                    continue;
                }
                $out[(string)$field][] = ['name' => (string)(is_array($f['name']) ? $f['name'][$i] : $f['name']), 'tmp_name' => $tmp, 'size' => (int)(is_array($f['size']) ? $f['size'][$i] : $f['size']), 'error' => $err];
            }
        }
        return $out;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function input(string $key): string
    {
        $v = $this->post[$key] ?? '';
        return is_string($v) ? $v : '';
    }
}
