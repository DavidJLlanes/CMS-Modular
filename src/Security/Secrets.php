<?php
declare(strict_types=1);

namespace LuxAeterna\Security;

use LuxAeterna\Core\Fs;
use LuxAeterna\Core\LuxException;

/**
 * Clave maestra de la aplicación (var/secrets/app.key, 0600, fuera de la raíz web y de Git).
 * Deriva subclaves por propósito (HKDF) y cifra secretos en reposo con libsodium (secretbox).
 */
final class Secrets
{
    private ?string $master = null;

    public function __construct(private readonly string $file)
    {
    }

    private function master(): string
    {
        if ($this->master !== null) {
            return $this->master;
        }
        if (!is_file($this->file)) {
            Fs::atomicWrite($this->file, bin2hex(random_bytes(32)) . "\n", 0600);
        }
        $hex = trim((string)@file_get_contents($this->file));
        if (!preg_match('/^[0-9a-f]{64}$/', $hex)) {
            throw new LuxException('La clave de la aplicación no es legible o no es válida.', 'app.key inválida');
        }
        return $this->master = (string)hex2bin($hex);
    }

    public function derive(string $purpose, int $length = 32): string
    {
        return hash_hkdf('sha256', $this->master(), $length, 'luxaeterna:' . $purpose);
    }

    public function hmac(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $data, $this->derive($purpose));
    }

    public function encrypt(string $purpose, string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1.' . sodium_bin2base64($nonce . sodium_crypto_secretbox($plain, $nonce, $this->derive($purpose)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function decrypt(string $purpose, string $blob): ?string
    {
        if (!str_starts_with($blob, 'v1.')) {
            return null;
        }
        try {
            $raw = sodium_base642bin(substr($blob, 3), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            return null;
        }
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($raw) <= $n) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $this->derive($purpose));
        return $plain === false ? null : $plain;
    }
}
