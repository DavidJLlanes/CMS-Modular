<?php
declare(strict_types=1);

namespace CMSModular\Security;

/**
 * IP del cliente. Solo se confía en X-Forwarded-For si la conexión directa viene de un proxy declarado en
 * security.trusted_proxies (IPs o CIDR). Si no, se usa REMOTE_ADDR: una cabecera falsificada no sirve para esquivar límites.
 */
final class ClientIp
{
    /** @param list<string> $trusted */
    public static function resolve(string $remote, ?string $xff, array $trusted): string
    {
        if ($trusted === [] || !self::inList($remote, $trusted) || $xff === null || $xff === '') {
            return $remote;
        }
        $hops = array_reverse(array_map('trim', explode(',', $xff)));
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!self::inList($hop, $trusted)) {
                return $hop;
            }
        }
        return $remote;
    }

    /** @param list<string> $list */
    public static function inList(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if (self::match($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    private static function match(string $ip, string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return $ip === $entry;
        }
        [$net, $bits] = explode('/', $entry, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b) || !ctype_digit($bits) || (int)$bits > strlen($a) * 8) {
            return false;
        }
        $bits = (int)$bits;
        $full = intdiv($bits, 8);
        if (substr($a, 0, $full) !== substr($b, 0, $full)) {
            return false;
        }
        if ($bits % 8 === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $bits % 8)) & 0xFF;
        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }
}
