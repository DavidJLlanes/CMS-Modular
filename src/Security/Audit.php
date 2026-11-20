<?php
declare(strict_types=1);

namespace CMSModular\Security;

use CMSModular\Core\Database;

/** Registro de auditoría: cuentas, accesos, permisos, publicación, plugins y claves. Nunca guarda secretos. */
final class Audit
{
    private const FORBIDDEN_META = ['password', 'token', 'secret', 'code', 'key', 'cookie'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,scalar|null> $meta */
    public function log(string $event, ?int $actorId, string $actorLabel, ?string $target = null, array $meta = [], ?string $ip = null): void
    {
        foreach (array_keys($meta) as $k) {
            foreach (self::FORBIDDEN_META as $bad) {
                if (stripos((string)$k, $bad) !== false) {
                    unset($meta[$k]);
                }
            }
        }
        $this->db->run(
            'INSERT INTO audit (ts, event, actor_user_id, actor_label, target, ip, meta) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [time(), $event, $actorId, $actorLabel, $target, $ip, $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 100, int $offset = 0): array
    {
        return $this->db->all('SELECT * FROM audit ORDER BY id DESC LIMIT ? OFFSET ?', [$limit, $offset]);
    }
}
