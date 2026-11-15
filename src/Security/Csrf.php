<?php
declare(strict_types=1);

namespace LuxAeterna\Security;

/**
 * Tokens CSRF sin estado: expiración.nonce.HMAC(clave de aplicación, contexto|expiración|nonce).
 * El contexto es el id de sesión (formularios autenticados) o "anon" (login, alta, recuperación).
 */
final class Csrf
{
    public function __construct(private readonly Secrets $secrets, private readonly int $ttl = 7200)
    {
    }

    public function token(string $context, ?int $now = null): string
    {
        $exp = ($now ?? time()) + $this->ttl;
        $nonce = bin2hex(random_bytes(8));
        return $exp . '.' . $nonce . '.' . $this->sign($context, $exp, $nonce);
    }

    public function verify(string $token, string $context, ?int $now = null): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || (int)$parts[0] < ($now ?? time())) {
            return false;
        }
        return hash_equals($this->sign($context, (int)$parts[0], $parts[1]), $parts[2]);
    }

    private function sign(string $context, int $exp, string $nonce): string
    {
        return $this->secrets->hmac('csrf', $context . '|' . $exp . '|' . $nonce);
    }
}
