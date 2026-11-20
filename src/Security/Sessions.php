<?php
declare(strict_types=1);

namespace CMSModular\Security;

use CMSModular\Core\Database;

/**
 * Sesiones en base de datos (no usa $_SESSION: funciona igual en hosting compartido).
 * La cookie lleva un token aleatorio de 256 bits; en la base solo se guarda su SHA-256.
 * Caducan por inactividad y por antigüedad absoluta, se rotan tras login y cambios de privilegios,
 * y se invalidan en bloque aumentando users.session_epoch.
 */
final class Sessions
{
    public const PENDING_TTL = 600;

    public function __construct(
        private readonly Database $db,
        private readonly Secrets $secrets,
        private readonly int $idle = 1800,
        private readonly int $absolute = 28800,
    ) {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function validToken(string $token): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    public function create(int $userId, string $kind, int $epoch, string $ip, string $ua, ?int $now = null): string
    {
        $now ??= time();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = $kind === 'pending_mfa' ? self::PENDING_TTL : $this->absolute;
        $this->db->run(
            'INSERT INTO sessions (id_hash, user_id, kind, epoch, created_at, last_seen_at, expires_at, ip_hash, ua_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::hash($token), $userId, $kind, $epoch, $now, $now, $now + $ttl, $this->secrets->hmac('ip', $ip), self::label($ua)]
        );
        return $token;
    }

    /** @return array{session:array<string,mixed>,user:array<string,mixed>}|null */
    public function find(string $token, ?int $now = null): ?array
    {
        if (!self::validToken($token)) {
            return null;
        }
        $now ??= time();
        $hash = self::hash($token);
        $s = $this->db->one('SELECT * FROM sessions WHERE id_hash = ?', [$hash]);
        if ($s === null) {
            return null;
        }
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [$s['user_id']]);
        $limit = $s['kind'] === 'pending_mfa' ? self::PENDING_TTL : $this->idle;
        if ($u === null || $u['status'] !== 'active' || (int)$u['session_epoch'] !== (int)$s['epoch']
            || $now >= (int)$s['expires_at'] || $now - (int)$s['last_seen_at'] > $limit) {
            $this->db->run('DELETE FROM sessions WHERE id_hash = ?', [$hash]);
            return null;
        }
        if ($now - (int)$s['last_seen_at'] >= 60) {
            $this->db->run('UPDATE sessions SET last_seen_at = ? WHERE id_hash = ?', [$now, $hash]);
        }
        return ['session' => $s, 'user' => $u];
    }

    /** Sustituye el token por uno nuevo (misma persona, nuevo identificador). Devuelve el nuevo token. */
    public function rotate(string $oldToken, string $kind, int $epoch, string $ip, string $ua, ?int $now = null): string
    {
        $old = $this->db->one('SELECT user_id FROM sessions WHERE id_hash = ?', [self::hash($oldToken)]);
        $this->destroy($oldToken);
        return $this->create((int)($old['user_id'] ?? 0), $kind, $epoch, $ip, $ua, $now);
    }

    public function destroy(string $token): void
    {
        $this->db->run('DELETE FROM sessions WHERE id_hash = ?', [self::hash($token)]);
    }

    public function destroyAllForUser(int $userId, ?string $exceptHash = null): int
    {
        return $this->db->run('DELETE FROM sessions WHERE user_id = ? AND id_hash <> ?', [$userId, $exceptHash ?? ''])->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        return $this->db->all('SELECT id_hash, kind, created_at, last_seen_at, expires_at, ua_label FROM sessions WHERE user_id = ? ORDER BY last_seen_at DESC', [$userId]);
    }

    /** Revoca por prefijo público (16 hex); solo sesiones del propietario indicado. */
    public function revokeByPrefix(string $prefix, int $ownerId): bool
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $prefix)) {
            return false;
        }
        return $this->db->run('DELETE FROM sessions WHERE user_id = ? AND substr(id_hash, 1, 16) = ?', [$ownerId, $prefix])->rowCount() > 0;
    }

    public function purge(?int $now = null): void
    {
        $now ??= time();
        $this->db->run('DELETE FROM sessions WHERE expires_at <= ? OR last_seen_at < ?', [$now, $now - $this->idle]);
    }

    private static function label(string $ua): string
    {
        $ua = preg_replace('/[^\x20-\x7E]/', '', $ua) ?? '';
        $os = 'Desconocido';
        foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $os = $name;
                break;
            }
        }
        $browser = 'navegador';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'CriOS' => 'Chrome', 'Safari' => 'Safari', 'curl' => 'curl'] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $browser = $name;
                break;
            }
        }
        return $browser . ' en ' . $os;
    }
}
