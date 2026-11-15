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
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly string $remoteAddr = '0.0.0.0',
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
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            rawurldecode($path),
            array_filter($_GET, 'is_string'),
            $_POST,
            array_filter($_COOKIE, 'is_string'),
            $headers,
            (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')
        );
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
