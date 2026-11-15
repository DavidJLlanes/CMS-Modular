<?php
declare(strict_types=1);

namespace LuxAeterna\Security;

use LuxAeterna\Core\Database;

/** Límite de intentos por cubo y clave (la clave se guarda con hash: no se almacenan correos ni IP en claro). */
final class Throttle
{
    public function __construct(private readonly Database $db, private readonly Secrets $secrets)
    {
    }

    private function key(string $raw): string
    {
        return $this->secrets->hmac('throttle', mb_strtolower($raw));
    }

    public function blocked(string $bucket, string $key, int $limit, int $window, ?int $now = null): bool
    {
        $now ??= time();
        $row = $this->db->one('SELECT count, window_start FROM throttle WHERE bucket = ? AND key_hash = ?', [$bucket, $this->key($key)]);
        return $row !== null && $now - (int)$row['window_start'] <= $window && (int)$row['count'] >= $limit;
    }

    public function fail(string $bucket, string $key, int $window, ?int $now = null): void
    {
        $now ??= time();
        $k = $this->key($key);
        $this->db->transaction(function () use ($bucket, $k, $window, $now): void {
            $row = $this->db->one('SELECT count, window_start FROM throttle WHERE bucket = ? AND key_hash = ?', [$bucket, $k]);
            if ($row === null || $now - (int)$row['window_start'] > $window) {
                $this->db->run('INSERT OR REPLACE INTO throttle (bucket, key_hash, count, window_start) VALUES (?, ?, 1, ?)', [$bucket, $k, $now]);
            } else {
                $this->db->run('UPDATE throttle SET count = count + 1 WHERE bucket = ? AND key_hash = ?', [$bucket, $k]);
            }
        });
    }

    public function clear(string $bucket, string $key): void
    {
        $this->db->run('DELETE FROM throttle WHERE bucket = ? AND key_hash = ?', [$bucket, $this->key($key)]);
    }

    public function purge(int $olderThan): void
    {
        $this->db->run('DELETE FROM throttle WHERE window_start < ?', [time() - $olderThan]);
    }
}
