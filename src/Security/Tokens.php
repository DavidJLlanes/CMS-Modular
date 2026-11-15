<?php
declare(strict_types=1);

namespace LuxAeterna\Security;

use LuxAeterna\Core\Database;

/** Tokens de un solo uso con caducidad para alta de cuenta (setup) y recuperación (reset). Solo se guarda su hash. */
final class Tokens
{
    public const TTL = ['setup' => 3600, 'reset' => 1800];

    public function __construct(private readonly Database $db)
    {
    }

    public function create(int $userId, string $purpose, ?int $now = null): string
    {
        $now ??= time();
        $this->db->run('UPDATE tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$now, $userId, $purpose]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->run(
            'INSERT INTO tokens (user_id, purpose, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $purpose, hash('sha256', $token), $now, $now + self::TTL[$purpose]]
        );
        return $token;
    }

    /** @return array<string,mixed>|null usuario asociado si el token es válido (sin consumirlo) */
    public function peek(string $token, string $purpose, ?int $now = null): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        return $this->db->one(
            'SELECT u.*, t.id AS token_id FROM tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.purpose = ? AND t.used_at IS NULL AND t.expires_at > ?',
            [hash('sha256', $token), $purpose, $now ?? time()]
        );
    }

    /** Consume el token de forma atómica: devuelve true solo la primera vez. */
    public function consume(int $tokenId, ?int $now = null): bool
    {
        return $this->db->run('UPDATE tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now ?? time(), $tokenId])->rowCount() === 1;
    }
}
