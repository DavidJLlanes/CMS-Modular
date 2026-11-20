<?php
declare(strict_types=1);

namespace CMSModular\Security;

/** Hash de contraseñas (Argon2id si existe; si no, bcrypt) y política mínima. */
final class Password
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;
    private const COMMON = ['password1234', '123456789012', 'qwertyuiop12', 'contraseña123', 'cms-modular123', 'administrador', 'passwordpassword', 'abcdefghijkl'];

    public static function hash(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $plain, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1])
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Trabajo equivalente a una verificación real, para no distinguir cuentas inexistentes por el tiempo. */
    public static function dummyVerify(string $plain): void
    {
        static $dummy = null;
        $dummy ??= self::hash('no-existe-' . bin2hex(random_bytes(8)));
        password_verify($plain, $dummy);
    }

    /** @return string|null mensaje de error, o null si la contraseña es aceptable */
    public static function validate(string $plain, string $email = ''): ?string
    {
        $len = mb_strlen($plain);
        if ($len < self::MIN_LENGTH) {
            return 'La contraseña debe tener al menos ' . self::MIN_LENGTH . ' caracteres.';
        }
        if ($len > self::MAX_LENGTH) {
            return 'La contraseña no puede superar ' . self::MAX_LENGTH . ' caracteres.';
        }
        $lower = mb_strtolower($plain);
        $local = mb_strtolower(explode('@', $email)[0]);
        if (in_array($lower, self::COMMON, true) || ($local !== '' && str_contains($lower, $local) && mb_strlen($local) >= 4)) {
            return 'La contraseña es demasiado previsible (no uses tu correo ni frases comunes).';
        }
        if (count(array_unique(mb_str_split($plain))) < 6) {
            return 'La contraseña tiene demasiados caracteres repetidos.';
        }
        return null;
    }
}
