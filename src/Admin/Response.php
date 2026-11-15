<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

final class Response
{
    /** @var list<string> */
    public array $cookies = [];

    /** @param array<string,string> $headers */
    public function __construct(public int $status = 200, public string $body = '', public array $headers = [])
    {
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return new self($status, '', ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        foreach ($this->cookies as $c) {
            header('Set-Cookie: ' . $c, false);
        }
        echo $this->body;
    }
}
