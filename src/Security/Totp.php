<?php
declare(strict_types=1);

namespace CMSModular\Security;

/** TOTP (RFC 6238, SHA-1, 6 dígitos, 30 s) sin dependencias. Con protección contra reutilización del código. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]+/', '', $text) ?? '');
        $bits = '';
        foreach (str_split($text) as $c) {
            $v = strpos(self::ALPHABET, $c);
            if ($v === false) {
                return '';
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secretBase32, int $step, int $digits = 6): string
    {
        $key = self::base32Decode($secretBase32);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $o = ord($hash[19]) & 0x0F;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string)($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * @return int|null paso (time-step) aceptado, o null. Rechaza pasos <= $lastStep (reutilización).
     */
    public static function verify(string $secretBase32, string $input, int $lastStep, ?int $now = null, int $window = 1): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (!preg_match('/^\d{6}$/', $input) || self::base32Decode($secretBase32) === '') {
            return null;
        }
        $current = intdiv($now ?? time(), 30);
        for ($i = -$window; $i <= $window; $i++) {
            $step = $current + $i;
            if ($step > $lastStep && hash_equals(self::code($secretBase32, $step), $input)) {
                return $step;
            }
        }
        return null;
    }

    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}
